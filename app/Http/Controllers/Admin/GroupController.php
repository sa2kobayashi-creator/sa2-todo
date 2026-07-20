<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\RedirectsWithFlash;
use App\Http\Controllers\Controller;
use App\Services\GroupService;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    use RedirectsWithFlash;

    public function __construct(private GroupService $groups) {}

    public function index(Request $request)
    {
        return view('admin.groups.index', array_merge($this->flashFromQuery($request), [
            'groups' => $this->groups->listAllForAdmin(),
        ]));
    }

    public function approve(Request $request, int $id)
    {
        $this->groups->approve($id, (int) $request->user()->id, $request->input('review_note'));

        return $this->redirectWithMessage('/admin/groups', __('グループを承認しました。'));
    }

    public function reject(Request $request, int $id)
    {
        $this->groups->reject($id, (int) $request->user()->id, $request->input('review_note'));

        return $this->redirectWithMessage('/admin/groups', __('グループを却下しました。'));
    }
}
