<?php

if ($argc < 3) {
    fwrite(STDERR, "Usage: php convert-ejs-view.php <source.ejs> <dest.blade.php> [headerActive]\n");
    exit(1);
}

$src = $argv[1];
$dest = $argv[2];
$active = $argv[3] ?? '';
$c = file_get_contents($src);

$headerInclude = $active === 'settings'
    ? "@include('partials.header', ['active' => 'settings', 'settingsSection' => \$section ?? 'holidays'])"
    : ($active ? "@include('partials.header', ['active' => '{$active}'])" : '');

$c = preg_replace("/<%- include\\('partials\\/header', \\{ active: '[^']+'(?:, settingsSection: section)? \\}\\) %>/", $headerInclude, $c);

$patterns = [
    '/<% Object\\.entries\\((\\w+)\\)\\.forEach\\(function \\(\\[value, label\\]\\) \\{ %>/' => '@foreach($$1 as $value => $label)',
    '/<% colorKeys\\.forEach\\(function \\(key\\) \\{ %>/' => '@foreach($colorKeys as $key)',
    '/<% reminderOptions\\.forEach\\(function \\(key\\) \\{ %>/' => '@foreach($reminderOptions as $key)',
    '/<% weekdayLabels\\.forEach\\(function \\(label, index\\) \\{ %>/' => '@foreach($weekdayLabels as $index => $label)',
    '/<% pinnedNotes\\.forEach\\(function \\(note\\) \\{ %>/' => '@foreach($pinnedNotes as $note)',
    '/<% otherNotes\\.forEach\\(function \\(note\\) \\{ %>/' => '@foreach($otherNotes as $note)',
    '/<% weekdayRules\\.forEach\\(function \\(rule\\) \\{ %>/' => '@foreach($weekdayRules as $rule)',
    '/<% holidays\\.forEach\\(function \\(item\\) \\{ %>/' => '@foreach($holidays as $item)',
    '/<% weeks\\.forEach\\(function \\(week\\) \\{ %>/' => '@foreach($weeks as $week)',
    '/<% week\\.forEach\\(function \\(cell\\) \\{ %>/' => '@foreach($week as $cell)',
    '/<% monthAgenda\\.forEach\\(function \\(item\\) \\{ %>/' => '@foreach($monthAgenda as $item)',
    '/<% \\}\\) %>/' => '@endforeach',
    '/<% if \\(notice\\) \\{ %>/' => '@if(!empty($notice))',
    '/<% if \\(error\\) \\{ %>/' => '@if(!empty($error))',
    '/<% if \\((.+?)\\) \\{ %>/' => '@if($1)',
    '/<% \\} else if \\((.+?)\\) \\{ %>/' => '@elseif($1)',
    '/<% \\} else \\{ %>/' => '@else',
    '/<% \\} %>/' => '@endif',
    '/href="\\/app\\.css"/' => 'href="{{ asset(\'app.css\') }}"',
];

foreach ($patterns as $pattern => $replacement) {
    $c = preg_replace($pattern, $replacement, $c);
}

$c = preg_replace('/<form([^>]*method="post"[^>]*)>/i', "<form$1>\n          @csrf", $c);

$replacements = [
    '<%= notice %>' => '{{ $notice }}',
    '<%= error %>' => '{{ $error }}',
    '<%= returnTo %>' => '{{ $returnTo }}',
    '<%= searchQuery %>' => '{{ $searchQuery }}',
    '<%= filterDate %>' => '{{ $filterDate }}',
    '<%= periodValue %>' => '{{ $periodValue }}',
    '<%= defaultRegisteredDate %>' => '{{ $defaultRegisteredDate }}',
    '<%= year %>' => '{{ $year }}',
    '<%= month %>' => '{{ $month }}',
    '<%= section %>' => '{{ $section }}',
    '<%= holidayYear %>' => '{{ $holidayYear }}',
    '<%= prevHolidayYear %>' => '{{ $prevHolidayYear }}',
    '<%= nextHolidayYear %>' => '{{ $nextHolidayYear }}',
    '<%= value %>' => '{{ $value }}',
    '<%= label %>' => '{{ $label }}',
    '<%= key %>' => '{{ $key }}',
    '<%= index %>' => '{{ $index }}',
    '<%= settingsPath(\'holidays\') %>' => '{{ $settingsPath(\'holidays\') }}',
    '<%= settingsPath(\'integration\') %>' => '{{ $settingsPath(\'integration\') }}',
    '<%= settingsPath(\'notifications\') %>' => '{{ $settingsPath(\'notifications\') }}',
    '<%= buildNotesQuery(filters) %>' => '{{ $buildNotesQuery($filters) }}',
    '<%= buildNotesQuery(Object.assign({}, filters, { archived: false })) %>' => '{{ $buildNotesQuery(array_merge($filters, [\'archived\' => false])) }}',
    '<%= buildNotesQuery(Object.assign({}, filters, { archived: true })) %>' => '{{ $buildNotesQuery(array_merge($filters, [\'archived\' => true])) }}',
];

foreach ($replacements as $from => $to) {
    $c = str_replace($from, $to, $c);
}

$c = preg_replace('/<%= settingsPath\\(([^)]+)\\) %>/', '{{ $settingsPath($1) }}', $c);
$c = preg_replace('/<%= truncateTitle\\(([^)]+)\\) %>/', '{{ $truncateTitle($1) }}', $c);
$c = preg_replace('/<%= getNoteDisplayTitle\\(([^)]+)\\) %>/', '{{ $getNoteDisplayTitle($1) }}', $c);
$c = preg_replace('/<%= getNoteRegisteredDate\\(([^)]+)\\) %>/', '{{ $getNoteRegisteredDate($1) }}', $c);
$c = preg_replace('/<%= formatPeriodLabel\\(([^)]+)\\) %>/', '{{ $formatPeriodLabel($1) }}', $c);
$c = preg_replace('/<%= formatNoteTooltip\\(([^)]+)\\) %>/', '{{ $formatNoteTooltip($1) }}', $c);
$c = preg_replace('/<%= formatEventTooltip\\(([^)]+)\\) %>/', '{{ $formatEventTooltip($1) }}', $c);

@mkdir(dirname($dest), 0777, true);
file_put_contents($dest, $c);
echo "Wrote {$dest}, remaining tags: ".preg_match_all('/<%/', $c)."\n";
