<?php

$files = [
    __DIR__.'/../resources/views/notes/index.blade.php',
    __DIR__.'/../resources/views/settings/index.blade.php',
    __DIR__.'/../resources/views/dashboard.blade.php',
];

foreach ($files as $file) {
    $c = file_get_contents($file);
    $replacements = [
        '@if(pinnedNotes.length > 0)' => '@if(count($pinnedNotes) > 0)',
        '@if(otherNotes.length > 0)' => '@if(count($otherNotes) > 0)',
        '@if(pagination.totalPages > 1)' => '@if(($pagination[\'totalPages\'] ?? 1) > 1)',
        '@if(pagination.page > 1)' => '@if($pagination[\'page\'] > 1)',
        '@if(pagination.page < pagination.totalPages)' => '@if($pagination[\'page\'] < $pagination[\'totalPages\'])',
        '@if(monthAgenda.length === 0)' => '@if(count($monthAgenda) === 0)',
        '@if(undated.length > 0)' => '@if(count($undated) > 0)',
        '@if(item.kind === \'note\')' => '@if(($item[\'kind\'] ?? \'\') === \'note\')',
        '@if(item.kind === \'todo\')' => '@if(($item[\'kind\'] ?? \'\') === \'todo\')',
        '@if(todo.startTime)' => '@if(!empty($todo[\'startTime\']))',
        '@if(cell.holidayName)' => '@if(!empty($cell[\'holidayName\']))',
        '@if(cellNotes.length > 0)' => '@if(count($cellNotes) > 0)',
        '@if(cellNotes.length > 1)' => '@if(count($cellNotes) > 1)',
        '@if(cellData.hiddenCount > 0)' => '@if(($cellData[\'hiddenCount\'] ?? 0) > 0)',
        '@if(cellMobileHidden > 0)' => '@if(($cellMobileHidden ?? 0) > 0)',
        '@elseif(section === \'integration\')' => '@elseif(($section ?? \'\') === \'integration\')',
        '@elseif(section === \'notifications\')' => '@elseif(($section ?? \'\') === \'notifications\')',
        '@if(section === \'holidays\')' => '@if(($section ?? \'\') === \'holidays\')',
        '<%= item.source === \'national\' ? \'日本祝日\' : item.source === \'national_ph\' ? \'PH祝日\' : \'独自\' %>' => '@switch($item[\'source\']) @case(\'national\') 日本祝日 @break @case(\'national_ph\') PH祝日 @break @default 独自 @endswitch',
        '<h3>{{ $month }}月の予定（<%= monthAgenda.length %>件）</h3>' => '<h3>{{ $month }}月の予定（{{ count($monthAgenda) }}件）</h3>',
        '<h3>期間未設定（<%= undated.length %>件）</h3>' => '<h3>期間未設定（{{ count($undated) }}件）</h3>',
        'const TODO_ITEMS = <%- JSON.stringify(todos) %>' => 'const TODO_ITEMS = @json($todosForJs ?? [])',
        'const NOTE_ITEMS = <%- JSON.stringify(notes) %>' => 'const NOTE_ITEMS = @json($notesForJs ?? [])',
        'const NOTE_COLORS = <%- JSON.stringify(noteColors) %>' => 'const NOTE_COLORS = @json($noteColors)',
        'const RETURN_TO = <%- JSON.stringify(returnTo) %>' => 'const RETURN_TO = @json($returnTo)',
        'const IMPORTANCE_LABELS = <%- JSON.stringify(importanceLabels) %>' => 'const IMPORTANCE_LABELS = @json($importanceLabels)',
        'const CATEGORY_LABELS = <%- JSON.stringify(categoryLabels) %>' => 'const CATEGORY_LABELS = @json($categoryLabels)',
        '<%= todo.completed ? \'done\' : \'\' %>' => '@class([\'done\' => !empty($todo[\'completed\'])])',
        '<%= todo.startDate !== todo.endDate ? \'is-range\' : \'\' %>' => '@class([\'is-range\' => ($todo[\'startDate\'] ?? null) !== ($todo[\'endDate\'] ?? null)])',
        'style="--note-bg: <%= notePalette.bg %>; --note-border: <%= notePalette.border %>"' => 'style="--note-bg: {{ $notePalette[\'bg\'] }}; --note-border: {{ $notePalette[\'border\'] }}"',
        '<span class="event-title">📝 <%= truncateTitle(getNoteDisplayTitle(note)) %></span>' => '<span class="event-title">📝 {{ $truncateTitle($getNoteDisplayTitle($note)) }}</span>',
        '他 <%= cellMobileHidden %> 件' => '他 {{ $cellMobileHidden }} 件',
        'data-note-id="<%= item.note.id %>"' => 'data-note-id="{{ $item[\'note\'][\'id\'] }}"',
        '<%= todo.startTime %>@if(todo.endTime && todo.endTime !== todo.startTime)～<%= todo.endTime %>@endif' => '{{ $todo[\'startTime\'] }}@if(!empty($todo[\'endTime\']) && $todo[\'endTime\'] !== $todo[\'startTime\'])～{{ $todo[\'endTime\'] }}@endif',
    ];
    foreach ($replacements as $from => $to) {
        $c = str_replace($from, $to, $c);
    }
    file_put_contents($file, $c);
    echo basename($file).': '.preg_match_all('/<%/', $c)." tags\n";
}
