@props(['size' => 'full'])
@php
    use Carbon\Carbon;

    $tz = $config['timezone'] ?? config('app.timezone', 'UTC');
    $daysAhead = (int) ($config['days_ahead'] ?? 7);
    $now = Carbon::now($tz);

    // Collect events from all calendar IDX_N responses
    $allEvents = collect();
    if (is_array($data)) {
        foreach ($data as $key => $calendarData) {
            // Handle both IDX_N (multiple calendars) and direct array (single calendar)
            $events = [];
            if (str_starts_with($key, 'IDX_')) {
                $events = $calendarData['data'] ?? $calendarData ?? [];
            } elseif ($key === 'data') {
                $events = $calendarData;
            } elseif (is_int($key)) {
                $events = [$calendarData];
            }
            if (!is_array($events)) continue;

            foreach ($events as $event) {
                if (!is_array($event) || !isset($event['summary'])) continue;

                $allDay = isset($event['start']['date']);
                try {
                    if ($allDay) {
                        $start = Carbon::parse($event['start']['date'], $tz)->startOfDay();
                        $end = Carbon::parse($event['end']['date'], $tz)->startOfDay();
                    } else {
                        $start = Carbon::parse($event['start']['dateTime'])->setTimezone($tz);
                        $end = Carbon::parse($event['end']['dateTime'])->setTimezone($tz);
                    }
                } catch (\Exception $e) {
                    continue;
                }

                $allEvents->push([
                    'summary' => $event['summary'],
                    'start' => $start,
                    'end' => $end,
                    'all_day' => $allDay,
                    'location' => $event['location'] ?? null,
                    'start_date' => $start->format('Y-m-d'),
                    'end_date' => $end->format('Y-m-d'),
                ]);
            }
        }
    }

    // Sort: all-day first per day, then by start time
    $allEvents = $allEvents->sort(function ($a, $b) {
        $dateCompare = strcmp($a['start_date'], $b['start_date']);
        if ($dateCompare !== 0) return $dateCompare;
        if ($a['all_day'] !== $b['all_day']) return $a['all_day'] ? -1 : 1;
        return $a['start']->timestamp - $b['start']->timestamp;
    });

    // Group by day, limited to days_ahead
    $days = collect();
    for ($i = 0; $i < $daysAhead; $i++) {
        $date = $now->copy()->addDays($i)->startOfDay();
        $dateStr = $date->format('Y-m-d');

        $dayEvents = $allEvents->filter(function ($event) use ($dateStr) {
            if ($event['all_day']) {
                return $dateStr >= $event['start_date'] && $dateStr < $event['end_date'];
            }
            return $event['start_date'] === $dateStr;
        })->values();

        if ($dayEvents->isEmpty()) continue;

        $label = match($i) {
            0 => 'Today',
            1 => 'Tomorrow',
            default => $date->format('l'),
        };

        $days->push([
            'label' => $label,
            'display_date' => $date->format('M j'),
            'events' => $dayEvents,
        ]);
    }

    $eventCount = $allEvents->filter(fn($e) => $e['start_date'] >= $now->format('Y-m-d') && $e['start_date'] <= $now->copy()->addDays($daysAhead)->format('Y-m-d'))->count();

    // Limit days and events per day for smaller sizes
    $maxDays = match($size) {
        'quadrant' => 2,
        'half_horizontal' => 3,
        'half_vertical' => 4,
        default => $daysAhead,
    };
    $maxEventsPerDay = match($size) {
        'quadrant' => 3,
        'half_horizontal' => 4,
        'half_vertical' => 5,
        default => 8,
    };
    $days = $days->take($maxDays)->map(function ($day) use ($maxEventsPerDay) {
        $day['events'] = $day['events']->take($maxEventsPerDay);
        return $day;
    });
@endphp

<x-trmnl::view size="{{ $size }}">
    <x-trmnl::layout>
        <div class="columns">
            <div class="column"
                 data-list-limit="true"
                 data-list-max-height="{{ $size === 'quadrant' ? '150' : ($size === 'half_horizontal' ? '170' : '390') }}">
                @forelse($days as $day)
                    <div class="item">
                        <div class="content">
                            <span class="label label--large font--bold">{{ $day['label'] }}, {{ $day['display_date'] }}</span>
                        </div>
                    </div>
                    @foreach($day['events'] as $event)
                        <div class="item">
                            @if($size !== 'quadrant')
                                <div class="meta"><span class="index"></span></div>
                            @endif
                            <div class="content">
                                @if($size === 'quadrant')
                                    @if($event['all_day'])
                                        <span class="description clamp--1">{{ $event['summary'] }}</span>
                                    @else
                                        <span class="description clamp--1">{{ $event['start']->format('H:i') }} {{ $event['summary'] }}</span>
                                    @endif
                                @else
                                    @if($event['all_day'])
                                        <span class="label label--outline">All day</span>
                                    @else
                                        <span class="label">{{ $event['start']->format('H:i') }} - {{ $event['end']->format('H:i') }}</span>
                                    @endif
                                    <span class="title title--small">{{ $event['summary'] }}</span>
                                    @if($event['location'] && $size === 'full' && !preg_match('#^https?://#i', $event['location']))
                                        <span class="description clamp--1">{{ $event['location'] }}</span>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endforeach
                @empty
                    <div class="item">
                        <div class="content text--center">
                            <span class="title">No upcoming events</span>
                        </div>
                    </div>
                @endforelse
            </div>
        </div>
    </x-trmnl::layout>

    <x-trmnl::title-bar title="{{ $trmnl['plugin_settings']['instance_name'] ?? 'Calendar' }}" instance="{{ $eventCount }} events"/>
</x-trmnl::view>
