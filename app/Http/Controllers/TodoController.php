<?php

namespace App\Http\Controllers;

use App\Services\TodoService;
use Illuminate\Http\Request;

class TodoController extends Controller
{
    use Concerns\RedirectsWithFlash;

    public function __construct(private TodoService $todos) {}

    public function index(Request $request)
    {
        $filters = $this->todos->parseFilters($request->query());
        $pageResult = $this->todos->filterTodosPage($this->todos->listTodos(), $filters);
        $editId = (int) $request->query('edit');
        $listQuery = $this->todos->buildTodosQuery($filters);
        $defaultStart = is_string($request->query('due')) ? $request->query('due') : '';

        return view('todos.index', [
            'todos' => collect($pageResult['dated']['items'])->map(fn ($t) => $this->todos->mapTodoListRow($t))->all(),
            'undatedTodos' => collect($pageResult['undated'])->map(fn ($t) => $this->todos->mapTodoListRow($t, ['undated' => true]))->all(),
            'pagination' => $pageResult['dated'],
            'datedTotal' => $pageResult['datedTotal'],
            'filters' => $filters,
            'listQuery' => $listQuery,
            'listReturnTo' => '/todos'.$listQuery.'#todo-list-panel',
            'buildTodosQuery' => fn (array $extra = []) => $this->todos->buildTodosQuery($filters, $extra),
            'todayFilterHref' => $this->todos->buildTodayFilterHref($filters),
            'clearFiltersHref' => $this->todos->buildClearFiltersHref(),
            'periodValue' => $filters['scope'] === 'today' ? '' : sprintf('%04d-%02d', $filters['year'], $filters['month']),
            'periodYearValue' => (string) $filters['year'],
            'periodMode' => $filters['periodMode'] ?? 'month',
            'editId' => $editId > 0 ? $editId : null,
            'defaultStartDate' => $defaultStart,
            'defaultEndDate' => $defaultStart,
            ...$this->flashFromQuery($request),
        ]);
    }

    public function store(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $titles = $this->todos->parseInput($request->input('titles'), [
            'splitByLine' => $request->boolean('splitByLine', true),
        ]);

        if (count($titles) === 0) {
            return $this->redirectWithMessage($returnTo, 'ToDo の内容を入力してください', 'error');
        }

        $dateMode = $request->input('dateMode', 'single');
        $startDate = $request->input('startDate');
        $endDate = $dateMode === 'range' ? $request->input('endDate') : $startDate;

        $this->todos->addTodos($titles, [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'importance' => $request->input('importance'),
            'category' => $request->input('category'),
            'startTime' => $request->input('startTime'),
            'endTime' => $request->input('endTime'),
            'reminders' => $request->input('reminders', []),
            'notifyVia' => $this->todos->parseNotifyViaFromBody($request->input('notifyVia')),
            'weekdays' => $request->input('weekdays', []),
            'excludeHolidays' => $request->input('excludeHolidays'),
            'excludeClosures' => $request->input('excludeClosures'),
        ]);

        $count = count($titles);

        return $this->redirectWithMessage($returnTo, "ToDo を {$count} 件追加しました");
    }

    public function update(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $updated = $this->todos->updateTodo($id, [
            'title' => $request->input('title'),
            'startDate' => $request->input('startDate'),
            'endDate' => $request->input('endDate'),
            'startTime' => $request->input('startTime'),
            'endTime' => $request->input('endTime'),
            'importance' => $request->input('importance'),
            'category' => $request->input('category'),
            'reminders' => $this->todos->parseRemindersFromBody($request->input('reminders')),
            'notifyVia' => $this->todos->parseNotifyViaFromBody($request->input('notifyVia')),
            'completed' => $request->has('completed') ? $request->boolean('completed') : null,
        ]);

        if (! $updated) {
            return $this->redirectWithMessage($returnTo, 'ToDo が見つかりません', 'error');
        }

        return $this->redirectWithMessage($returnTo, 'ToDo を更新しました');
    }

    public function toggle(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $this->todos->toggleTodo($id);

        return redirect($returnTo);
    }

    public function destroy(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $this->todos->deleteTodo($id);

        return $this->redirectWithMessage($returnTo, 'ToDo を削除しました');
    }

    public function duplicate(Request $request, int $id)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $this->todos->duplicateTodo($id);

        return $this->redirectWithMessage($returnTo, 'ToDo を複製しました');
    }

    public function bulkComplete(Request $request)
    {
        return $this->bulkSetCompleted($request, true, '一括で完了にしました');
    }

    public function bulkUncomplete(Request $request)
    {
        return $this->bulkSetCompleted($request, false, '一括で未完了にしました');
    }

    public function bulkDelete(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $ids = $this->todos->parseIdList($request->input('ids'));
        $count = $this->todos->bulkDelete($ids);

        return $this->redirectWithMessage($returnTo, "{$count} 件削除しました");
    }

    public function bulkDuplicate(Request $request)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $ids = $this->todos->parseIdList($request->input('ids'));
        $count = $this->todos->bulkDuplicate($ids);

        return $this->redirectWithMessage($returnTo, "{$count} 件複製しました");
    }

    private function bulkSetCompleted(Request $request, bool $completed, string $message)
    {
        $returnTo = $this->safeReturnTo($request->input('returnTo'));
        $ids = $this->todos->parseIdList($request->input('ids'));
        $count = $this->todos->bulkSetCompleted($ids, $completed);

        return $this->redirectWithMessage($returnTo, "{$count} 件{$message}");
    }
}
