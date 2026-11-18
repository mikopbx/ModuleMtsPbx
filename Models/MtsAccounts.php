<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 */

namespace Modules\ModuleMtsPbx\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Портал MTS Автосекретарь 2.0, с которым синхронизируется история звонков.
 */
class MtsAccounts extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Название портала для интерфейса и логов.
     *
     * @Column(type="string", nullable=true, default="")
     */
    public $name = '';

    /**
     * API ключ (JWT) портала.
     *
     * @Column(type="string", nullable=true, default="")
     */
    public $authApiKey = '';

    /**
     * Домен портала, извлечённый из API ключа.
     *
     * @Column(type="string", nullable=true, default="")
     */
    public $domain = '';

    /**
     * Смещение времени, часов.
     *
     * @Column(type="string", nullable=true, default="0")
     */
    public $gap = '0';

    /**
     * Позиция синхронизации (время MTS API, Europe/Moscow).
     *
     * @Column(type="string", nullable=true, default="")
     */
    public $offset = '';

    /**
     * @Column(type="integer", nullable=true, default="1")
     */
    public $enabled = 1;

    /**
     * 1 — скачивать записи разговоров; 0 — синхронизировать только CDR (mts_rec_status = 'skipped').
     *
     * @Column(type="integer", nullable=true, default="1")
     */
    public $downloadRecords = 1;

    /**
     * 1 — портал перенесён из однопортальной версии и использует старый формат ID `fs-mts-<callId>`.
     *
     * @Column(type="integer", nullable=true, default="0")
     */
    public $legacyIdFormat = 0;

    public function initialize(): void
    {
        $this->setSource('m_ModuleMtsPbxAccounts');
        parent::initialize();
    }

    public function isLegacyIdFormat(): bool
    {
        return (string)$this->legacyIdFormat === '1';
    }

    public function isEnabled(): bool
    {
        return (string)$this->enabled === '1';
    }

    /**
     * Строка таблицы (массив) или модель: NULL у портала, созданного до появления колонки, — скачивать.
     */
    public static function isDownloadRecordsEnabled($value): bool
    {
        return $value === null || $value === '' || (string)$value === '1';
    }
}
