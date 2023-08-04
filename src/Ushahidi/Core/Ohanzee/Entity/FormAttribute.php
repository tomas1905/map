<?php

/**
 * Ushahidi FormEntity Attribute
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi\Platform
 * @copyright  2014 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

namespace Ushahidi\Core\Ohanzee\Entity;

use Ushahidi\Core\Data\FormAttribute as FormAttributeEntity;
use Ushahidi\Core\Ohanzee\StaticEntity;

class FormAttribute extends StaticEntity implements FormAttributeEntity
{
    protected $id;
    protected $key;
    protected $label;
    protected $instructions;
    protected $input;
    protected $type;
    protected $required;
    protected $default;
    protected $priority;
    protected $options = [];
    protected $cardinality;
    protected $config = [];
    protected $form_stage_id;
    protected $response_private;

    // StatefulData
    protected function getDerived()
    {
        return [
            'form_stage_id' => ['form_stage', 'form_stage.id'], /* alias */
        ];
    }

    // DataTransformer
    protected function getDefinition()
    {
        return [
            'id'            => 'int',
            'key'           => 'string',
            'label'         => 'string',
            'instructions'  => 'string',
            'input'         => 'string',
            'type'          => 'string',
            'required'      => 'bool',
            'default'       => 'string',
            'priority'      => 'int',
            'options'       => '*json',
            'cardinality'   => 'int',
            'config'        => '*json',
            'form_stage'    => false, /* alias */
            'form_stage_id' => 'int',
            'response_private' => 'bool',
        ];
    }

    // Entity
    public function getResource()
    {
        return 'form_attributes';
    }

    public function __toString()
    {
        return json_encode([
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'instructions' => $this->instructions,
            'input' => $this->input,
            'type' => $this->type,
            'required' => $this->required,
            'default' => $this->default,
            'priority' => $this->priority,
            'options' => $this->options,
        ]);
    }
}
