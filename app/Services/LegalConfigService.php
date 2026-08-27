<?php

namespace App\Services;

use App\Models\MediaStorageSetting;

/**
 * 特商法表記・問い合わせ窓口の事業者情報。
 * 設定 → 公開販売 が正。未保存のときは .env（config/legal.php）を使う。
 */
class LegalConfigService
{
    /** @var list<string> */
    public const FIELDS = [
        'operator_name',
        'operator_trade_name',
        'operator_manager',
        'address',
        'phone',
        'phone_hours',
        'contact_email',
        'privacy_contact_email',
        'invoice_registration_number',
    ];

    public function configRow(): MediaStorageSetting
    {
        return MediaStorageSetting::forUse(MediaStorageSetting::PROVIDER_LEGAL);
    }

    public function get(string $key): string
    {
        $row = $this->configRow();
        if ($row->exists && (bool) $row->setting('configured', false)) {
            return trim((string) $row->setting($key, ''));
        }

        $fromDb = trim((string) $row->setting($key, ''));
        if ($fromDb !== '') {
            return $fromDb;
        }

        return trim((string) config('legal.'.$key, ''));
    }

    /** @return array<string, string> */
    public function values(): array
    {
        $out = [];
        foreach (self::FIELDS as $key) {
            $out[$key] = $this->get($key);
        }

        return $out;
    }

    public function requiredComplete(): bool
    {
        return $this->get('operator_name') !== ''
            && $this->get('address') !== ''
            && $this->get('phone') !== ''
            && $this->get('contact_email') !== '';
    }

    public function privacyContactEmail(): string
    {
        $email = $this->get('privacy_contact_email');

        return $email !== '' ? $email : $this->get('contact_email');
    }

    /**
     * DB に値がある項目だけ config を上書きする。空の DB でテストの config() を壊さない。
     */
    public function applyRuntime(): void
    {
        $row = $this->configRow();
        if (! $row->exists) {
            return;
        }

        $overlay = [];
        $configured = (bool) $row->setting('configured', false);
        foreach (self::FIELDS as $key) {
            $value = trim((string) $row->setting($key, ''));
            if ($configured || $value !== '') {
                $overlay['legal.'.$key] = $value;
            }
        }
        if ($overlay !== []) {
            config($overlay);
        }
    }

    /** @param array<string, mixed> $input */
    public function save(array $input): MediaStorageSetting
    {
        $row = MediaStorageSetting::writeForProvider(MediaStorageSetting::PROVIDER_LEGAL);
        $settings = $row->settingsArray();
        foreach (self::FIELDS as $key) {
            $settings[$key] = trim((string) ($input[$key] ?? ''));
        }
        $settings['configured'] = true;

        $row->fill([
            'enabled' => $this->requiredCompleteFrom($settings),
            'settings' => $settings,
        ]);
        $row->save();
        $this->applyRuntime();

        return $row->fresh() ?? $row;
    }

    /** @return array<string, mixed> */
    public function formState(): array
    {
        $row = MediaStorageSetting::forProvider(MediaStorageSetting::PROVIDER_LEGAL);
        $values = $this->values();

        return $values + [
            'saved_in_db' => $row->exists && (bool) $row->setting('configured', false),
            'required_complete' => $this->requiredComplete(),
        ];
    }

    /** @param array<string, string> $settings */
    private function requiredCompleteFrom(array $settings): bool
    {
        return trim($settings['operator_name'] ?? '') !== ''
            && trim($settings['address'] ?? '') !== ''
            && trim($settings['phone'] ?? '') !== ''
            && trim($settings['contact_email'] ?? '') !== '';
    }
}
