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
use Phalcon\Forms\Element\TextArea;
use Phalcon\Forms\Element\Hidden;
class ModuleMtsPbxForm extends Form
{

    public function initialize($entity = null, $options = null) :void
    {
        $this->add(new Hidden('id', ['value' => $entity->id]));
        $rows = max(round(strlen($entity->text_area_field) / 95), 2);
        $this->add(new TextArea('authApiKey', ['rows' => $rows]));
        $this->add(new Text('inLogin', ['rows' => $rows]));
        $this->add(new Text('inPassword', ['rows' => $rows]));
    }
}