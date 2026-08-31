<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\GroupMessageAttachment;
use App\Models\MusicTrack;
use App\Models\Note;
use App\Models\Photo;
use App\Models\PhotoAlbum;
use App\Models\Todo;
use App\Models\User;
use App\Models\MessagingConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserAccountDeletionService
{
    public function __construct(
        private PhotoService $photos,
        private MusicService $music,
        private NoteService $notes,
        private GoogleCalendarService $googleCalendar,
        private GoogleCalendarOAuthService $googleOauth,
        private MessagingLinkService $messagingLinks,
        private StripeBillingService $stripeBilling,
    ) {}

    /**
     * ストレージ上のファイルを掃除してからユーザー行を削除する。
     * 本人退会・管理者削除の共通入口。
     */
    public function delete(User $user): void
    {
        $userId = (int) $user->id;

        // 課金が残るとクレームになるため、データ削除より先に Stripe を止める
        $this->stripeBilling->cancelAllSubscriptionsForDeletion($user);

        $this->purgePhotoAssets($userId);
        $this->purgeMusicTracks($userId);
        $this->purgeNotes($userId);
        $this->purgeTodos($userId);
        $this->purgeGroupMessageAttachmentFiles($userId);
        $this->purgeOwnedGroups($userId);
        $this->revokeIntegrations($user);

        GroupMessage::query()
            ->where('recipient_user_id', $userId)
            ->update(['recipient_user_id' => null]);

        DB::transaction(function () use ($user) {
            // 削除直前にログイン不能化（途中失敗でアカウントが残ってもパスワードは生きる）
            $user->forceFill([
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
            ])->save();
            $user->delete();
        });
    }

    private function purgePhotoAssets(int $userId): void
    {
        $ids = Photo::query()->where('user_id', $userId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids !== []) {
            // Cloudinary も消し、afterResponse ではなく同期で掃除してから User を消す
            $this->photos->bulkDeletePhotos($userId, $ids, true, false);
        }

        PhotoAlbum::query()->where('user_id', $userId)->delete();
    }

    private function purgeMusicTracks(int $userId): void
    {
        $ids = MusicTrack::query()->where('user_id', $userId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($ids as $id) {
            try {
                $this->music->deleteTrack($userId, $id);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function purgeNotes(int $userId): void
    {
        $ids = Note::query()->where('user_id', $userId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($ids as $id) {
            try {
                $this->notes->deleteNote($userId, $id);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function purgeTodos(int $userId): void
    {
        Todo::query()->where('user_id', $userId)->delete();
    }

    private function purgeGroupMessageAttachmentFiles(int $userId): void
    {
        $attachments = GroupMessageAttachment::query()
            ->where('user_id', $userId)
            ->get(['id', 'disk', 'path']);

        foreach ($attachments as $attachment) {
            $this->deleteDiskPath((string) $attachment->disk, (string) $attachment->path);
        }
    }

    private function purgeOwnedGroups(int $userId): void
    {
        $groups = Group::query()->where('owner_user_id', $userId)->get();
        foreach ($groups as $group) {
            $attachments = GroupMessageAttachment::query()
                ->whereHas('message', fn ($q) => $q->where('group_id', $group->id))
                ->get(['disk', 'path']);
            foreach ($attachments as $attachment) {
                $this->deleteDiskPath((string) $attachment->disk, (string) $attachment->path);
            }
            try {
                $group->delete();
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function revokeIntegrations(User $user): void
    {
        $connection = $this->googleCalendar->connectionFor($user);
        if ($connection) {
            try {
                $this->googleOauth->revokeAndDelete($connection);
            } catch (\Throwable $e) {
                Log::warning('Account deletion: Google revoke failed', [
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);
                $connection->delete();
            }
        }

        foreach ([MessagingConnection::PROVIDER_LINE, MessagingConnection::PROVIDER_MESSENGER] as $provider) {
            try {
                $this->messagingLinks->disconnect($user, $provider);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function deleteDiskPath(string $disk, string $path): void
    {
        $disk = trim($disk);
        $path = trim($path);
        if ($disk === '' || $path === '') {
            return;
        }

        try {
            $storage = Storage::disk($disk);
            if ($storage->exists($path)) {
                $storage->delete($path);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
