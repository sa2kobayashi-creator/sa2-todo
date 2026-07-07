<?php

$src = 'D:/Development/todo-app/views/todos.ejs';
$dest = 'D:/Development/todo_sa2/resources/views/todos/index.blade.php';
$c = file_get_contents($src);

$replacements = [
    "<%- include('partials/header', { active: 'todos' }) %>" => "@include('partials.header', ['active' => 'todos'])",
    "<%- include('partials/todo-table-colgroup') %>" => "@include('partials.todo-table-colgroup')",
    "<%- include('partials/todo-table-header') %>" => "@include('partials.todo-table-header')",
    "<%- include('partials/todo-table-row', { row, editUrl }) %>" => "@include('partials.todo-table-row', ['row' => \$row, 'editUrl' => \$editUrl])",
    "<%- include('partials/todo-edit-row', { todo: row, listReturnTo, importanceLabels, categoryLabels, reminderOptions, reminderLabels, notifyViaOptions, notifyViaLabels }) %>" => "@include('partials.todo-edit-row', ['todo' => \$row, 'listReturnTo' => \$listReturnTo])",
    '<% Object.entries(importanceLabels).forEach(function ([value, label]) { %>' => '@foreach($importanceLabels as $value => $label)',
    '<% Object.entries(categoryLabels).forEach(function ([value, label]) { %>' => '@foreach($categoryLabels as $value => $label)',
    '<% weekdayLabels.forEach(function (label, index) { %>' => '@foreach($weekdayLabels as $index => $label)',
    '<% reminderOptions.forEach(function (key) { %>' => '@foreach($reminderOptions as $key)',
    '<% notifyViaOptions.forEach(function (key) { %>' => '@foreach($notifyViaOptions as $key)',
    '<% todos.forEach(function (row) { %>' => '@foreach($todos as $row)',
    '<% undatedTodos.forEach(function (row) { %>' => '@foreach($undatedTodos as $row)',
    '<% }) %>' => '@endforeach',
    '<% if (notice) { %>' => '@if($notice)',
    '<% if (error) { %>' => '@if($error)',
    '<% if (filters.scope === \'today\') { %>' => '@if(($filters[\'scope\'] ?? \'\') === \'today\')',
    '<% if (!(filters.categories || []).length) { %>' => '@if(empty($filters[\'categories\']))',
    '<% if (filters.scope === \'today\' && filters.todayDate) { %>' => '@if(($filters[\'scope\'] ?? \'\') === \'today\' && !empty($filters[\'todayDate\']))',
    '<% } else if (filters.scope === \'year\') { %>' => '@elseif(($filters[\'scope\'] ?? \'\') === \'year\')',
    '<% if (pagination.total > 0) { %>' => '@if(($pagination[\'total\'] ?? 0) > 0)',
    '<% if (pagination.totalPages > 1) { %>' => '@if(($pagination[\'totalPages\'] ?? 1) > 1)',
    '<% if (pagination.page > 1) { %>' => '@if($pagination[\'page\'] > 1)',
    '<% if (pagination.page < pagination.totalPages) { %>' => '@if($pagination[\'page\'] < $pagination[\'totalPages\'])',
    '<% if (todos.length === 0) { %>' => '@if(count($todos) === 0)',
    '<% if (undatedTodos.length > 0) { %>' => '@if(count($undatedTodos) > 0)',
    '<% if (editId === row.id) { %>' => '@if($editId === $row[\'id\'])',
    '<% } else { %>' => '@else',
    '<% } %>' => '@endif',
    'locals.filters' => '$filters',
    'locals.periodValue' => '$periodValue',
    'locals.periodYearValue' => '$periodYearValue',
    'locals.periodMode' => '$periodMode',
    'locals.clearFiltersHref' => '$clearFiltersHref',
    'locals.nationalHolidayDates' => '$nationalHolidayDates',
    'locals.closureDates' => '$closureDates',
    'filters.scope' => '$filters[\'scope\']',
    'filters.todayDate' => '$filters[\'todayDate\']',
    'filters.year' => '$filters[\'year\']',
    'filters.status' => '$filters[\'status\']',
    'filters.importance' => '$filters[\'importance\']',
    'filters.categories' => '$filters[\'categories\']',
    'pagination.total' => '$pagination[\'total\']',
    'pagination.page' => '$pagination[\'page\']',
    'pagination.perPage' => '$pagination[\'perPage\']',
    'pagination.totalPages' => '$pagination[\'totalPages\']',
    'todos.length' => 'count($todos)',
    'undatedTodos.length' => 'count($undatedTodos)',
    'row.id' => '$row[\'id\']',
    'row.completed' => '$row[\'completed\']',
    'listQuery.includes(\'?\')' => 'str_contains($listQuery, \'?\')',
    'typeof todayFilterHref !== \'undefined\' ? todayFilterHref : buildTodayFilterHref(filters)' => '$todayFilterHref',
    '<% const editUrl = \'/todos\' + listQuery + (listQuery.includes(\'?\') ? \'&\' : \'?\') + \'edit=\' + row.id + \'#todo-list-panel\' %>' => '@php $editUrl = \'/todos\'.$listQuery.(str_contains($listQuery, \'?\') ? \'&\' : \'?\').\'edit=\'.$row[\'id\']. \'#todo-list-panel\'; @endphp',
    '<% const undatedEditUrl = \'/todos\' + listQuery + (listQuery.includes(\'?\') ? \'&\' : \'?\') + \'edit=\' + row.id + \'#todo-list-panel\' %>' => '@php $editUrl = \'/todos\'.$listQuery.(str_contains($listQuery, \'?\') ? \'&\' : \'?\').\'edit=\'.$row[\'id\']. \'#todo-list-panel\'; @endphp',
    '<%- include(\'partials/todo-table-row\', { row, editUrl: undatedEditUrl }) %>' => '@include(\'partials.todo-table-row\', [\'row\' => $row, \'editUrl\' => $editUrl])',
    '<% filters.categories.map(function (v) { return categoryLabels[v] }).join(\'、\') %>' => '{{ collect($filters[\'categories\'] ?? [])->map(fn($v) => $categoryLabels[$v] ?? $v)->join(\'、\') }}',
    'href="/app.css"' => 'href="{{ asset(\'app.css\') }}"',
    '<form class="add" method="post" action="/todos" id="add-form">' => '<form class="add" method="post" action="/todos" id="add-form">'."\n          @csrf",
    '<form id="row-action-form" method="post" action="/todos/0/toggle"' => '<form id="row-action-form" method="post" action="/todos/0/toggle"'."\n      @csrf",
    'let nationalHolidayDatesCache = new Set(<%- JSON.stringify(locals.nationalHolidayDates || []) %>)' => 'let nationalHolidayDatesCache = new Set(@json($nationalHolidayDates ?? []))',
    'let closureDatesCache = new Set(<%- JSON.stringify(locals.closureDates || []) %>)' => 'let closureDatesCache = new Set(@json($closureDates ?? []))',
    'buildTodosQuery({ page: pagination.page - 1 })' => '$buildTodosQuery([\'page\' => $pagination[\'page\'] - 1])',
    'buildTodosQuery({ page: pagination.page + 1 })' => '$buildTodosQuery([\'page\' => $pagination[\'page\'] + 1])',
    'Math.min(pagination.page * pagination.perPage, pagination.total)' => 'min($pagination[\'page\'] * $pagination[\'perPage\'], $pagination[\'total\'])',
    '(pagination.page - 1) * pagination.perPage + 1' => '($pagination[\'page\'] - 1) * $pagination[\'perPage\'] + 1',
];

foreach ($replacements as $from => $to) {
    $c = str_replace($from, $to, $c);
}

// <%= expr %> -> {{ expr }} with $ prefix for simple vars
$c = preg_replace_callback('/<%= (.+?) %>/', function ($m) {
    $expr = $m[1];
    $map = [
        'notice' => '$notice',
        'error' => '$error',
        'listReturnTo' => '$listReturnTo',
        'defaultStartDate' => '$defaultStartDate',
        'defaultEndDate' => '$defaultEndDate',
        'value' => '$value',
        'label' => '$label',
        'index' => '$index',
        'key' => '$key',
        'reminderLabels[key]' => '$reminderLabels[$key]',
        'notifyViaLabels[key]' => '$notifyViaLabels[$key]',
    ];
    if (isset($map[$expr])) {
        return '{{ '.$map[$expr].' }}';
    }
    if (preg_match("/^value === '([^']+)' \\? 'selected' : ''$/", $expr, $mm)) {
        return "@selected(\$value === '{$mm[1]}')";
    }
    if (preg_match("/^\\(filters\\.categories \\|\\| \\[\\]\\)\\.includes\\(value\\) \\? 'checked' : ''$/", $expr)) {
        return '@checked(in_array($value, $filters[\'categories\'] ?? [], true))';
    }
    if (preg_match("/^filters\\.scope === 'today' \\? 'active' : ''$/", $expr)) {
        return '@class([\'active\' => ($filters[\'scope\'] ?? \'\') === \'today\'])';
    }
    if (str_contains($expr, 'filters.') || str_contains($expr, 'pagination.') || str_contains($expr, 'locals.')) {
        return '{{ '.$expr.' }}';
    }

    return '{{ $'.preg_replace('/^([a-zA-Z_][a-zA-Z0-9_]*)/', '$1', $expr).' }}';
}, $c);

// Fix attribute patterns left as ejs
$c = preg_replace('/<%= \\(\\$filters\[\'periodMode\'\\].*?\\) === \'month\' \\? \'checked\' : \'\' %>/', '@checked(($filters[\'periodMode\'] ?? $periodMode ?? \'month\') === \'month\')', $c);
$c = preg_replace('/<%= \\$filters\[\'periodMode\'\\].*?\'checked\' : \'\' %>/', '@checked(($filters[\'periodMode\'] ?? \'\') === \'year\' || ($filters[\'scope\'] ?? \'\') === \'year\' || ($periodMode ?? \'month\') === \'year\')', $c);
$c = preg_replace('/<%= \\$filters\[\'scope\'\\] === \'today\' \\? \'\' : \\(\\$periodValue \\|\\| \'\'\\) %>/', 'value="{{ ($filters[\'scope\'] ?? \'\') === \'today\' ? \'\' : ($periodValue ?? \'\') }}"', $c);
$c = preg_replace('/<%= \\$filters\[\'scope\'\\] === \'today\' \\? \'placeholder="未設定"\' : \'\' %>/', '@if(($filters[\'scope\'] ?? \'\') === \'today\') placeholder="未設定" @endif', $c);

file_put_contents($dest, $c);
echo "Converted ".substr_count($c, '@')." blade directives\n";
echo "Remaining EJS tags: ".preg_match_all('/<%/', $c)."\n";
