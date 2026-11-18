<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 9 2018
 *
 */
namespace Modules\ModuleMtsPbx\App\Forms;

use Phalcon\Forms\Form;
use Phalcon\Forms\Element\Text;
use Phalcon\Forms\Element\Hidden;
class ModuleMtsPbxForm extends Form
{

    public function initialize($entity = null, $options = null) :void
    {
        // Порталы MTS (API ключ, смещение времени) редактируются отдельным списком в шаблоне.
        $this->add(new Hidden('id', ['value' => $entity->id]));
        $this->add(new Text('inLogin'));
        $this->add(new Text('inPassword'));
    }
}
