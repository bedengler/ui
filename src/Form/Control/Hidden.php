<?php

declare(strict_types=1);

namespace atk4\ui\Form\Control;

use atk4\ui\Form;

/**
 * Input element for a form field.
 */
class Hidden extends Input
{
    public $ui = '';
    public $layoutWrap = false;
    public $inputType = 'hidden';
}