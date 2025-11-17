<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Forms;

use Icinga\Module\Perfdatagraphselasticsearch\Client\Elasticsearch;

use Icinga\Forms\ConfigForm;

use Exception;

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
        $this->addElement('text', 'elasticsearch_api_url', [
            'label' => t('API URL'),
            'description' => t('Comma-separated URLs for Elasticsearch including the scheme'),
            'required' => true,
            'placeholder' => 'http://node2:9200,http://node2:9200',
        ]);

        // $this->addElement('text', 'elasticsearch_api_index', [
        //     'label' => t('Name of the index to query'),
        //     'description' => t('Name of the index configured in Icinga'),
        //     'required' => true,
        // ]);

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
        $index = $form->getValue('elasticsearch_api_index', 'icinga2');
        $username = $form->getValue('elasticsearch_api_username', '');
        $password = $form->getValue('elasticsearch_api_password', '');
        $tlsVerify = (bool) $form->getValue('elasticsearch_api_tls_insecure', false);

        try {
            $c = new Elasticsearch($baseURI, $index, $username, $password, $timeout, $tlsVerify);
        } catch (Exception $e) {
            return ['output' => 'General error: ' . $e->getMessage(), 'error' => true];
        }

        $status = $c->status();

        return $status;
    }
}
