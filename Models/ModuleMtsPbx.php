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

use MikoPBX\Common\Models\Providers;
use MikoPBX\Modules\Models\ModulesModelsBase;
use Phalcon\Mvc\Model\Relation;

class ModuleMtsPbx extends ModulesModelsBase
{

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;


    /**
     * Устарело: API ключ однопортальной версии. Перенесён в MtsAccounts при обновлении,
     * колонка сохранена для возможности отката на предыдущую версию модуля.
     *
     * @Column(type="string", nullable=true, default="")
     */
    public $authApiKey='';

    /**
     * Устарело: см. MtsAccounts::$offset.
     *
     * @Column(type="string", nullable=true, default="")
     */
    public $offset='';

    /**
     * Устарело: см. MtsAccounts::$gap.
     *
     * @Column(type="string", nullable=true, default="0")
     */
    public $gap = '0';

    /**
     * 1 — GET /pbxcore/mts-pbx/cdr требует Basic Auth (inLogin/inPassword).
     * Без default: у существующих установок колонка добавляется со значением NULL
     * и при установке выставляется в 0, новые установки получают 1.
     *
     * @Column(type="integer", nullable=true)
     */
    public $cdrAuthEnabled;

    /**
     * 1 — однопортальные настройки уже перенесены в MtsAccounts.
     *
     * @Column(type="integer", nullable=true)
     */
    public $accountsMigrated;

    /**
     * @Column(type="string", nullable=true)
     */
    public $inLogin='';

    /**
     * @Column(type="string", nullable=true)
     */
    public $inPassword='';

    /**
     * Text field example
     *
     * @Column(type="string", nullable=true)
     */
    public $text_field;

    /**
     * TextArea field example
     *
     * @Column(type="string", nullable=true)
     */
    public $text_area_field;

    /**
     * Password field example
     *
     * @Column(type="string", nullable=true)
     */
    public $password_field;

    /**
     * Integer field example
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $integer_field;

    /**
     * CheckBox
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $checkbox_field;

    /**
     * Toggle
     *
     * @Column(type="integer", default="1", nullable=true)
     */
    public $toggle_field;

    /**
     * Dropdown menu
     *
     * @Column(type="string", nullable=true)
     */
    public $dropdown_field;

    /**
     * Returns dynamic relations between module models and common models
     * MikoPBX check it in ModelsBase after every call to keep data consistent
     *
     * There is example to describe the relation between Providers and ModuleMtsPbx models
     *
     * It is important to duplicate the relation alias on message field after Models\ word
     *
     * @param $calledModelObject
     *
     * @return void
     */
    public static function getDynamicRelations(&$calledModelObject): void
    {
//        if (is_a($calledModelObject, Providers::class)) {
//            $calledModelObject->belongsTo(
//                'id',
//                ModuleMtsPbx::class,
//                'dropdown_field',
//                [
//                    'alias'      => 'ModuleMtsPbxProvider',
//                    'foreignKey' => [
//                        'allowNulls' => 0,
//                        'message'    => 'Models\ModuleMtsPbxProvider',
//                        'action'     => Relation::ACTION_RESTRICT
//                        // запретить удалять провайдера если есть ссылки в модуле
//                    ],
//                ]
//            );
//        }
    }

    public function initialize(): void
    {
        $this->setSource('m_ModuleMtsPbx');
        $this->hasOne(
            'dropdown_field',
            Providers::class,
            'id',
            [
                'alias'      => 'Providers',
                'foreignKey' => [
                    'allowNulls' => true,
                    'action'     => Relation::NO_ACTION,
                ],
            ]
        );
        parent::initialize();
    }


}