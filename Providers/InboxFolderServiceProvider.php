<?php

namespace Modules\InboxFolder\Providers;

use App\Conversation;
use App\Folder;
use Illuminate\Support\ServiceProvider;

class InboxFolderServiceProvider extends ServiceProvider
{
    const TYPE_INBOX = 10;

    // Fake ID offset — real folder IDs are sequential from 1 and will never reach this.
    const FAKE_ID_OFFSET = 100000;

    /**
     * Generate a deterministic fake ID for a mailbox's Inbox folder.
     */
    private static function fakeId($mailboxId)
    {
        return self::FAKE_ID_OFFSET + (int) $mailboxId;
    }

    /**
     * Create an in-memory Inbox folder for a given mailbox + user.
     */
    private function getInboxFolder($mailboxId, $userId)
    {
        $folder = new Folder();
        $folder->id = self::fakeId($mailboxId);
        $folder->mailbox_id = $mailboxId;
        $folder->user_id = $userId;
        $folder->type = self::TYPE_INBOX;
        $folder->active_count = 0;
        $folder->total_count = 0;

        return $folder;
    }

    /**
     * Insert Inbox folder at the very top of the folder list.
     */
    private function insertInboxFolder($folders, $inboxFolder)
    {
        $result = collect();
        $result->push($inboxFolder);
        foreach ($folders as $f) {
            $result->push($f);
        }
        return $result;
    }

    public function boot()
    {
        // ── Inbox folder in sidebar ─────────────────────────────────

        \Eventy::addFilter('mailbox.folders', function ($folders, $mailbox) {
            $user = auth()->user();
            if (!$user) {
                return $folders;
            }
            return $this->insertInboxFolder($folders, $this->getInboxFolder($mailbox->id, $user->id));
        }, 10, 2);

        // ── Inbox folder on dashboard ───────────────────────────────

        \Eventy::addFilter('mailbox.main_folders', function ($mainFolders, $mailbox) {
            $user = auth()->user();
            if (!$user || !empty($mainFolders)) {
                return $mainFolders;
            }

            // Reproduce the default main folders, then prepend Inbox.
            $folders = $mailbox->folders()
                ->where(function ($query) use ($user) {
                    $query->whereIn('type', [Folder::TYPE_UNASSIGNED, Folder::TYPE_ASSIGNED, Folder::TYPE_DRAFTS])
                        ->orWhere(function ($q) use ($user) {
                            $q->where('type', Folder::TYPE_MINE)->where('user_id', $user->id);
                        })
                        ->orWhere(function ($q) use ($user) {
                            $q->where('type', Folder::TYPE_STARRED)->where('user_id', $user->id);
                        });
                })
                ->orderBy('type')
                ->get();

            return $this->insertInboxFolder($folders, $this->getInboxFolder($mailbox->id, $user->id));
        }, 10, 2);

        // ── Folder name ─────────────────────────────────────────────

        \Eventy::addFilter('folder.type_name', function ($name, $folder) {
            if ($folder->type == self::TYPE_INBOX) {
                return __('Inbox');
            }
            return $name;
        }, 20, 2);

        // ── Folder icon ─────────────────────────────────────────────

        \Eventy::addFilter('folder.type_icon', function ($icon, $folder) {
            if ($folder->type == self::TYPE_INBOX) {
                return 'inbox';
            }
            return $icon;
        }, 20, 2);

        // ── Inbox folder conversation query ─────────────────────────

        \Eventy::addFilter('folder.conversations_query', function ($query, $folder, $userId) {
            if ($folder->type != self::TYPE_INBOX) {
                return $query;
            }

            // Build fresh query — the "else" branch in getQueryByFolder used
            // $folder->conversations() which queries by our fake id, returning nothing.
            $newQuery = Conversation::where('mailbox_id', $folder->mailbox_id)
                ->where('state', Conversation::STATE_PUBLISHED)
                ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING]);

            // Re-apply assigned-only restriction. The core applies it to the old
            // query (which we just discarded), so we must apply it ourselves.
            if (!\Helper::isConsole()) {
                $user = auth()->user();
                if ($user && $user->id == $userId && $user->canSeeOnlyAssignedConversations()) {
                    $newQuery->where('user_id', $userId);
                }
            }

            return $newQuery;
        }, 20, 3);

        // ── Inbox folder sort order ─────────────────────────────────

        \Eventy::addFilter('folder.conversations_order_by', function ($orderBy, $type) {
            if ($type == self::TYPE_INBOX) {
                return [['last_reply_at' => 'desc']];
            }
            return $orderBy;
        }, 20, 2);

        // ── Skip default counter update for Inbox folder ────────────

        \Eventy::addFilter('folder.update_counters', function ($shouldSkip, $folder) {
            if ($folder->type == self::TYPE_INBOX) {
                return true;
            }
            return $shouldSkip;
        }, 20, 2);

        // ── Inbox folder count ──────────────────────────────────────

        \Eventy::addFilter('folder.count', function ($count, $folder, $counter, $folders) {
            if ($folder->type != self::TYPE_INBOX) {
                return $count;
            }

            if (\Helper::isConsole()) {
                return $count;
            }

            $user = auth()->user();
            if (!$user) {
                return 0;
            }

            $query = Conversation::where('mailbox_id', $folder->mailbox_id)
                ->where('state', Conversation::STATE_PUBLISHED)
                ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING]);

            // For restricted users, only count their assigned conversations
            if ($user->canSeeOnlyAssignedConversations()) {
                $query->where('user_id', $user->id);
            }

            return $query->count();
        }, 20, 4);
    }

    public function register()
    {
        //
    }
}
