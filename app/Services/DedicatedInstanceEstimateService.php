<?php

namespace App\Services;

/**
 * 専用インスタンス見積の計算。画面プレビューと仕様書の金額を一致させる。
 */
class DedicatedInstanceEstimateService
{
    /**
     * @param  array{
     *   client_name?: string,
     *   users?: int,
     *   include_mailbox?: bool,
     *   maintenance_cycle?: string,
     *   notes?: string
     * }  $input
     * @return array<string, mixed>
     */
    public function build(array $input = []): array
    {
        $users = max(1, (int) ($input['users'] ?? config('commercial.included_users', 5)));
        $included = max(1, (int) config('commercial.included_users', 5));
        $extraUsers = max(0, $users - $included);
        $includeMailbox = (bool) ($input['include_mailbox'] ?? false);
        $cycle = ($input['maintenance_cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';

        $setup = max(0, (int) config('commercial.setup_fee_yen', 50000));
        $monthlyBase = max(0, (int) config('commercial.monthly_base_yen', 8000));
        $extraUserYen = max(0, (int) config('commercial.extra_user_yen_monthly', 1000));
        $mailboxMonthly = max(0, (int) config('commercial.mailbox_yen_monthly', 300));
        $mailboxYearly = max(0, (int) config('commercial.mailbox_yen_yearly', 3000));
        $yearlyMonths = max(1, min(12, (int) config('commercial.yearly_maintenance_months_charged', 11)));

        $monthlyMaintenance = $monthlyBase + ($extraUsers * $extraUserYen);
        $mailboxLine = $includeMailbox
            ? ($cycle === 'yearly' ? $mailboxYearly : $mailboxMonthly)
            : 0;

        if ($cycle === 'yearly') {
            $recurring = ($monthlyMaintenance * $yearlyMonths) + $mailboxLine;
            $recurringLabel = __('年額（保守）');
        } else {
            $recurring = $monthlyMaintenance + $mailboxLine;
            $recurringLabel = __('月額');
        }

        $lines = [
            [
                'label' => __('初期構築'),
                'amount' => $setup,
                'note' => __('専用環境・ドメイン／SSL・初期ユーザー・ストレージ接続・引き渡し'),
            ],
            [
                'label' => $cycle === 'yearly'
                    ? __('保守（年額・:users名まで込み、超過 :extra 名）', ['users' => $included, 'extra' => $extraUsers])
                    : __('保守（月額・:users名まで込み、超過 :extra 名）', ['users' => $included, 'extra' => $extraUsers]),
                'amount' => $cycle === 'yearly' ? $monthlyMaintenance * $yearlyMonths : $monthlyMaintenance,
                'note' => $extraUsers > 0
                    ? __('追加ユーザー :count 名 × :yen 円', [
                        'count' => $extraUsers,
                        'yen' => number_format($extraUserYen),
                    ])
                    : __('基本ユーザー枠内'),
            ],
        ];

        if ($includeMailbox) {
            $lines[] = [
                'label' => $cycle === 'yearly'
                    ? __('メールボックス（年額）')
                    : __('メールボックス（月額）'),
                'amount' => $mailboxLine,
                'note' => __('@sa2-plus.com 1アドレス'),
            ];
        }

        return [
            'clientName' => trim((string) ($input['client_name'] ?? '')),
            'users' => $users,
            'includedUsers' => $included,
            'extraUsers' => $extraUsers,
            'includeMailbox' => $includeMailbox,
            'maintenanceCycle' => $cycle,
            'notes' => trim((string) ($input['notes'] ?? '')),
            'setupFee' => $setup,
            'monthlyMaintenance' => $monthlyMaintenance,
            'recurringTotal' => $recurring,
            'recurringLabel' => $recurringLabel,
            'initialTotal' => $setup,
            'lines' => $lines,
            'taxNote' => __('表示は税別の初期案です。正式契約で確定します。'),
            'issuedAt' => now()->timezone(config('app.timezone'))->format('Y-m-d'),
            'productName' => config('app.name', 'Sa2 Plus'),
        ];
    }
}
