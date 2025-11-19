<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Forms;

use Icinga\Module\Perfdatagraphselasticsearch\Client\ElasticsearchClient;
use Icinga\Module\Perfdatagraphselasticsearch\Client\ElasticsearchDatastreamClient;

use Icinga\Forms\ConfigForm;

/**
 * PerfdataGraphsElasticsearchConfigForm represents the configuration form for the PerfdataGraphs Elasticsearch Module.
 */
class PerfdataGraphsElasticsearchConfigForm extends ConfigForm
{
    public function init()
    {
        $this->setName('form_config_perfdataelasticsearch');
        $this->setSubmitLabel($this->translate('Save Changes'));
        $this->setValidatePartial(true);
    }

    public function createElements(array $formData)
    {
        $this->addElement('select', 'elasticsearch_icinga_writer', [
            'description' => t('Which Icinga2 Elasticsearch Writer is used to write data'),
            'label' => t('Icinga2 Writer'),
            'multiOptions' => array_merge(
                ['' => sprintf(' - %s - ', t('Please choose'))],
                [
                    'ElasticsearchWriter' => 'ElasticsearchWriter',
                    'ElasticsearchDatastreamWriter' => 'ElasticsearchDatastreamWriter',
                ]
            ),
            'disable' => [''],
            'required' => true,
            'class' => 'autosubmit',
            'value' => ''
        ]);

        if (isset($formData['elasticsearch_icinga_writer']) && $formData['elasticsearch_icinga_writer'] === 'ElasticsearchWriter') {
            $this->addElement('text', 'elasticsearch_api_index', [
                'label' => t('Icinga2 Index'),
                'description' => t('Name of the index that is configured in Icinga2'),
                'required' => true,
                'placeholder' => 'icinga2',
            ]);
        }

        $this->addElement('text', 'elasticsearch_api_url', [
            'label' => t('API URLs'),
            'description' => t('Comma-separated URLs for Elasticsearch including the scheme. Example: https://node2:9200,https://node2:9200'),
            'required' => true,
            'placeholder' => 'https://node2:9200,https://node2:9200',
        ]);

        $this->addElement('text', 'elasticsearch_api_username', [
            'label' => t('API basic auth username'),
            'description' => t('The user for HTTP basic auth. Not used if empty')
        ]);

        $this->addElement('password', 'elasticsearch_api_password', [
            'label' => t('API HTTP basic auth password'),
            'description' => t('The password for HTTP basic auth. Not used if empty'),
            'renderPassword' => true
        ]);

        $this->addElement('number', 'elasticsearch_api_timeout', [
            'label' => t('HTTP timeout in seconds'),
            'description' => t('HTTP timeout for the API in seconds. Should be higher than 0'),
            'required' => true,
            'placeholder' => 10,
        ]);

        $this->addElement('checkbox', 'elasticsearch_api_tls_insecure', [
            'description' => t('Skip the TLS verification'),
            'label' => t('Skip the TLS verification')
        ]);
    }

    public function addSubmitButton()
    {
        parent::addSubmitButton()
            ->getElement('btn_submit')
            ->setDecorators(['ViewHelper']);

        $this->addElement(
            'submit',
            'backend_validation',
            [
                'ignore' => true,
                'label' => $this->translate('Validate Configuration'),
                'data-progress-label' => $this->translate('Validation in Progress'),
                'decorators' => ['ViewHelper']
            ]
        );

        $this->setAttrib('data-progress-element', 'backend-progress');
        $this->addElement(
            'note',
            'backend-progress',
            [
                'decorators' => [
                    'ViewHelper',
                    ['Spinner', ['id' => 'backend-progress']]
                ]
            ]
        );

        $this->addDisplayGroup(
            ['btn_submit', 'backend_validation', 'backend-progress'],
            'submit_validation',
            [
                'decorators' => [
                    'FormElements',
                    ['HtmlTag', ['tag' => 'div', 'class' => 'control-group form-controls']]
                ]
            ]
        );

        return $this;
    }

    public function isValidPartial(array $formData)
    {
        if ($this->getElement('backend_validation')->isChecked() && parent::isValid($formData)) {
            $validation = static::validateFormData($this);
            if ($validation !== null) {
                $this->addElement(
                    'note',
                    'inspection_output',
                    [
                        'order' => 0,
                        'value' => '<strong>' . $this->translate('Validation Log') . "</strong>\n\n"
                            . $validation['output'],
                        'decorators' => [
                            'ViewHelper',
                            ['HtmlTag', ['tag' => 'pre', 'class' => 'log-output']],
                        ]
                    ]
                );

                if (isset($validation['error'])) {
                    $this->warning(sprintf(
                        $this->translate('Failed to successfully validate the configuration: %s'),
                        $validation['error']
                    ));
                    return false;
                }
            }

            $this->info($this->translate('The configuration has been successfully validated.'));
        }

        return true;
    }

    public static function validateFormData($form): array
    {
        $baseURI = $form->getValue('elasticsearch_api_url', 'http://localhost:9200');
        $timeout = (int) $form->getValue('elasticsearch_api_timeout', 10);
        $username = $form->getValue('elasticsearch_api_username', '');
        $password = $form->getValue('elasticsearch_api_password', '');
        $index = $form->getValue('elasticsearch_api_index', 'icinga2');
        $tlsVerify = (bool) $form->getValue('elasticsearch_api_tls_insecure', false);

        // TODO: Not yet implemented
        $maxDataPoints = 10000;
        // $maxDataPoints = $form->getValue('elasticsearch_max_data_points', 10000);

        $writer = $form->getValue('elasticsearch_icinga_writer', '');

        if ($writer === 'ElasticsearchWriter') {
            $c = new ElasticsearchClient($baseURI, $username, $password, $maxDataPoints, $timeout, $tlsVerify, $index);
        } else {
            $c = new ElasticsearchDatastreamClient($baseURI, $username, $password, $maxDataPoints, $timeout, $tlsVerify);
        }

        $status = $c->status();

        return $status;
    }
}
