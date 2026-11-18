<?php
/**
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 2 2019
 */

/*
 * https://docs.phalcon.io/4.0/en/db-models
 *
 */

namespace Modules\ModuleMtsPbx\Models;
use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Events\Manager as EventsManager;

/**
 * Class CallDetailRecords
 *
 * @package MikoPBX\Common\Models
 *
 * @Indexes(
 *     [name='start', columns=['start'], type=''],
 *     [name='UNIQUEID', columns=['UNIQUEID'], type=''],
 *     [name='linkedid', columns=['linkedid'], type='']
 * )
 */
class CallHistory extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Time when the call starts.
     * @Column(type="string", nullable=true)
     */
    public ?string $start = '';

    /**
     * Time when the call ends.
     * @Column(type="string", nullable=true)
     */
    public ?string $endtime = '';

    /**
     * Answer status of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $answer = '';

    /**
     * Source channel of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $src_chan = '';

    /**
     * Source number of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $src_num = '';

    /**
     * Destination channel of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $dst_chan = '';

    /**
     * Destination number of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $dst_num = '';

    /**
     * Unique ID of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $UNIQUEID = '';

    /**
     * Linked ID of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $linkedid = '';

    /**
     * DID (Direct Inward Dialing) of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $did = '';

    /**
     * Disposition of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $disposition = '';

    /**
     * Recording file of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $recordingfile = '';

    /**
     *  Source account of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $from_account = '';

    /**
     * Destination account of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $to_account = '';

    /**
     * Dial status of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $dialstatus = '';

    /**
     * Application name of the call.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $appname = '';

    /**
     *  Transfer status of the call.
     *
     * @Column(type="integer", nullable=true)
     */
    public ?string $transfer = '';

    /**
     * Indicator if the call is associated with an application.
     *
     * @Column(type="string", length=1, nullable=true)
     */
    public ?string $is_app = '';

    /**
     * Duration of the call in seconds.
     *
     * @Column(type="integer", nullable=true)
     */
    public ?string $duration = '';

    /**
     * Duration of the call in billing seconds.
     *
     * @Column(type="integer", nullable=true)
     */
    public ?string $billsec = '';

    /**
     * Indicator if the work is completed.
     *
     * @Column(type="string", length=1, nullable=true)
     */
    public ?string $work_completed = '';

    /**
     * Source call ID.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $src_call_id = '';

    /**
     * Destination call ID.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $dst_call_id = '';

    /**
     *  Verbose call ID.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $verbose_call_id = '';

    /**
     *  Indicator if the call is a transferred call.
     *
     * @Column(type="integer", nullable=true)
     */
    public ?string $a_transfer = '0';

    /**
     * Длительность записи разговора по данным MTS (recDur).
     *
     * @Column(type="integer", nullable=true)
     */
    public ?string $mts_rec_dur = '0';

    /**
     * Состояние загрузки записи разговора:
     *   pending — ожидает дозагрузки (recDur > 0, mp3 ещё не получен),
     *   ok      — mp3 успешно сохранён,
     *   none    — у звонка нет записи (recDur = 0),
     *   gone    — запись недоступна (например, истёк срок хранения в MTS),
     *   skipped — запись есть, но не скачивается: у портала выключено «Скачивать записи разговоров».
     *
     * @Column(type="string", length=16, nullable=true)
     */
    public ?string $mts_rec_status = '';

    /**
     * ID портала (MtsAccounts). NULL — строка загружена однопортальной версией модуля,
     * относится к порталу с legacyIdFormat = 1.
     *
     * @Column(type="integer", nullable=true)
     */
    public ?string $mts_account_id = null;

    /**
     * Исходный callId MTS (без префикса и ID портала). NULL — см. mts_account_id,
     * callId восстанавливается из linkedid старого формата.
     *
     * @Column(type="string", nullable=true)
     */
    public ?string $mts_call_id = null;

    public function initialize(): void
    {
        $this->setSource('mts_cdr');
        parent::initialize();
        // ModelsBase после каждого save()/delete() публикует изменение в WorkerModelsEvents (Beanstalk)
        // и чистит кеш через Redis KEYS. Для строк CDR это не нужно никому, а при загрузке истории
        // (десятки тысяч строк) заметно тормозит запись и нагружает WorkerModelsEvents.
        $this->setEventsManager(new EventsManager());
    }
}