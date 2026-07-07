<?php

$files = array_slice($argv, 1);
if (empty($files)) {
    $files = glob(__DIR__.'/../resources/views/**/*.blade.php') ?: [];
    $files = array_merge($files, glob(__DIR__.'/../resources/views/*.blade.php') ?: []);
}

foreach ($files as $file) {
    if (! is_file($file)) {
        continue;
    }
    $c = file_get_contents($file);

    $c = str_replace(
        "<%- include('partials/note-card', { note, noteColors, returnTo, showArchived, getNoteRegisteredDate, highlightId }) %>",
        "@include('partials.note-card', ['note' => \$note, 'returnTo' => \$returnTo, 'showArchived' => \$showArchived, 'highlightId' => \$highlightId])",
        $c
    );

    $c = preg_replace('/<%= rule\\.name %>/', '{{ $rule[\'name\'] }}', $c);
    $c = preg_replace('/<%= rule\\.startDate %>/', '{{ $rule[\'startDate\'] }}', $c);
    $c = preg_replace('/<%= rule\\.endDate %>/', '{{ $rule[\'endDate\'] }}', $c);
    $c = preg_replace('/<%= rule\\.id %>/', '{{ $rule[\'id\'] }}', $c);
    $c = preg_replace('/<%= exDate %>/', '{{ $exDate }}', $c);
    $c = preg_replace('/<%= item\\.date %>/', '{{ $item[\'date\'] }}', $c);
    $c = preg_replace('/<%= item\\.name %>/', '{{ $item[\'name\'] }}', $c);
    $c = preg_replace('/<%= item\\.id %>/', '{{ $item[\'id\'] }}', $c);
    $c = preg_replace('/<%= item\\.source %>/', '{{ $item[\'source\'] }}', $c);
    $c = preg_replace('/<%= weekdayLabels\\[dow\\] %>/', '{{ $weekdayLabels[$dow] }}', $c);
    $c = preg_replace('/<% rule\\.weekdays\\.forEach\\(function \\(dow\\) \\{ %>/', '@foreach($rule[\'weekdays\'] as $dow)', $c);
    $c = preg_replace('/<% rule\\.exceptions\\.forEach\\(function \\(exDate\\) \\{ %>/', '@foreach($rule[\'exceptions\'] as $exDate)', $c);

    $c = preg_replace('/<%= section === \'([^\']+)\' \\? \'([^\']*)\' : \'([^\']*)\' %>/', '@class([\'$2\' => ($section ?? \'\') === \'$1\'])', $c);
    $c = preg_replace('/class="<%= section === \'([^\']+)\' \\? \'active\' : \'\' %>"/', '@class([\'active\' => ($section ?? \'\') === \'$1\'])', $c);

    $c = preg_replace('/<%= prev\\.year %>/', '{{ $prev[\'year\'] }}', $c);
    $c = preg_replace('/<%= prev\\.month %>/', '{{ $prev[\'month\'] }}', $c);
    $c = preg_replace('/<%= next\\.year %>/', '{{ $next[\'year\'] }}', $c);
    $c = preg_replace('/<%= next\\.month %>/', '{{ $next[\'month\'] }}', $c);
    $c = preg_replace('/<%= cell\\.date %>/', '{{ $cell[\'date\'] }}', $c);
    $c = preg_replace('/<%= cell\\.day %>/', '{{ $cell[\'day\'] }}', $c);
    $c = preg_replace('/<%= cell\\.holidayName \\|\\| \'\' %>/', '{{ $cell[\'holidayName\'] ?? \'\' }}', $c);
    $c = preg_replace('/<%= cell\\.holidayName %>/', '{{ $cell[\'holidayName\'] }}', $c);
    $c = preg_replace('/<%= cell\\.inMonth \\? \'1\' : \'0\' %>/', '{{ !empty($cell[\'inMonth\']) ? \'1\' : \'0\' }}', $c);
    $c = preg_replace('/<%= cellNotes\\.length %>/', '{{ count($cellNotes) }}', $c);
    $c = preg_replace('/<%= todo\\.id %>/', '{{ $todo[\'id\'] }}', $c);
    $c = preg_replace('/<%= todo\\.title %>/', '{{ $todo[\'title\'] }}', $c);
    $c = preg_replace('/<%= todo\\.category %>/', '{{ $todo[\'category\'] }}', $c);
    $c = preg_replace('/<%= todo\\.importance %>/', '{{ $todo[\'importance\'] }}', $c);
    $c = preg_replace('/<%= note\\.id %>/', '{{ $note[\'id\'] }}', $c);
    $c = preg_replace('/<%= note\\.color %>/', '{{ $note[\'color\'] }}', $c);
    $c = preg_replace('/<%= chipTimeLabel %>/', '{{ $chipTimeLabel }}', $c);
    $c = preg_replace('/<%= cellData\\.hiddenCount %>/', '{{ $cellData[\'hiddenCount\'] }}', $c);

    $c = preg_replace('/<% cellData\\.visible\\.forEach\\(function \\(todo\\) \\{ %>/', '@foreach($cellData[\'visible\'] as $todo)', $c);
    $c = preg_replace('/<% cellMobileVisible\\.forEach\\(function \\(item\\) \\{ %>/', '@foreach($cellMobileVisible as $item)', $c);
    $c = preg_replace('/<% const todo = item\\.todo %>/', '@if(($item[\'kind\'] ?? \'\') === \'todo\') @php $todo = $item[\'todo\']; @endphp', $c);
    $c = preg_replace('/<% const note = item\\.note %>/', '@elseif(($item[\'kind\'] ?? \'\') === \'note\') @php $note = $item[\'note\']; @endphp', $c);
    $c = preg_replace('/<% const notePalette = noteColors\\[note\\.color\\] \\|\\| noteColors\\.default %>/', '@php $notePalette = $noteColors[$note[\'color\']] ?? $noteColors[\'default\']; @endphp', $c);

    $c = preg_replace('/<%= noteColors\\[key\\]\\.bg %>/', '{{ $noteColors[$key][\'bg\'] }}', $c);
    $c = preg_replace('/<%= noteColors\\[key\\]\\.border %>/', '{{ $noteColors[$key][\'border\'] }}', $c);
    $c = preg_replace('/<%= noteColors\\[key\\]\\.label %>/', '{{ $noteColors[$key][\'label\'] }}', $c);
    $c = preg_replace('/<%= key === \'default\' \\? \'is-selected\' : \'\' %>/', '@class([\'is-selected\' => $key === \'default\'])', $c);
    $c = preg_replace('/<%= filterDate \\? \'disabled\' : \'\' %>/', '@disabled($filterDate)', $c);

    $c = preg_replace('/<%= pagination\\.total %>/', '{{ $pagination[\'total\'] }}', $c);
    $c = preg_replace('/<%= pagination\\.page %>/', '{{ $pagination[\'page\'] }}', $c);
    $c = preg_replace('/<%= pagination\\.totalPages %>/', '{{ $pagination[\'totalPages\'] }}', $c);
    $c = preg_replace('/<%= buildNotesQuery\\(filters, \\{ page: pagination\\.page - 1, note: highlightId \\}\\) %>/', '{{ $buildNotesQuery($filters, [\'page\' => $pagination[\'page\'] - 1, \'note\' => $highlightId]) }}', $c);
    $c = preg_replace('/<%= buildNotesQuery\\(filters, \\{ page: pagination\\.page \\+ 1, note: highlightId \\}\\) %>/', '{{ $buildNotesQuery($filters, [\'page\' => $pagination[\'page\'] + 1, \'note\' => $highlightId]) }}', $c);
    $c = preg_replace('/<%= showArchived \\? \'アーカイブされたメモはありません。\' : \'メモがありません。上の欄から追加できます。\' %>/', '{{ $showArchived ? \'アーカイブされたメモはありません。\' : \'メモがありません。上の欄から追加できます。\' }}', $c);
    $c = preg_replace('/const highlightId = <%- JSON\\.stringify\\(highlightId\\) %>/', 'const highlightId = @json($highlightId)', $c);

    $c = preg_replace('/class="calendar-weekday <%= index === 0 \\? \'sun\' : index === 6 \\? \'sat\' : \'\' %>"/', 'class="calendar-weekday {{ $index === 0 ? \'sun\' : ($index === 6 ? \'sat\' : \'\') }}"', $c);

    file_put_contents($file, $c);
    echo basename($file).': '.preg_match_all('/<%/', $c)." tags left\n";
}
