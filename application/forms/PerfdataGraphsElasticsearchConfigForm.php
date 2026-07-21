<?php

namespace Icinga\Module\Perfdatagraphselasticsearch\Forms;

use Icinga\Module\Perfdatagraphselasticsearch\Client\ElasticsearchClient;
use Icinga\Module\Perfdatagraphselasticsearch\Client\OTLPMetricsClient;

use Icinga\Forms\ConfigForm;

use Zend_Validate_Callback;

/**
 * PerfdataGraphsElasticsearchConfigForm represents the configuration form for the PerfdataGraphs Elasticsearch Module.
 * TODO: Icinga Web 2.14 introduced a new Web\Form\ConfigForm, we can migrate when 2.14 is more prevalent
 * Then we can also use ipl Validators.
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
                    'OTLPMetricsWriter' => 'OTLPMetricsWriter',
                ]
            ),
            'disable' => [''],
            'required' => true,
            'class' => 'autosubmit',
            'value' => ''
        ]);

        $this->addElement('text', 'elasticsearch_api_index', [
            'label' => t('Icinga2 Index'),
            'description' => t('Name of the index that is configured in Icinga2'),
            'required' => true,
            'placeholder' => 'icinga2',
        ]);

        $this->addElement('text', 'elasticsearch_api_url', [
            'label' => t('API URLs'),
            'description' => t('Comma-separated URLs for Elasticsearch including the scheme. Example: https://node2:9200,https://node2:9200'),
            'required' => true,
            'placeholder' => 'https://node2:9200,https://node2:9200',
        ]);


        $this->addElement('select', 'elasticsearch_api_auth_method', [
            'label' => 'API authentication method',
            'description' => 'Authentication method to use for the API',
            'multiOptions' => [
                'none' => t('None'),
                'basic' => 'Basic Auth',
                'token' => 'Token',
            ],
            'class' => 'autosubmit',
            'required' => false,
        ]);


        if (isset($formData['elasticsearch_api_auth_method']) && $formData['elasticsearch_api_auth_method'] === 'basic') {
            $this->addElement('text', 'elasticsearch_api_auth_username', [
                'label' => t('API basic auth username'),
                'description' => t('The user for HTTP basic auth. Not used if empty')
            ]);

            $this->addElement('password', 'elasticsearch_api_auth_password', [
                'label' => t('API HTTP basic auth password'),
                'description' => t('The password for HTTP basic auth. Not used if empty'),
                'renderPassword' => true
            ]);
        }

        if (isset($formData['elasticsearch_api_auth_method']) && $formData['elasticsearch_api_auth_method'] === 'token') {
            $this->addElement('text', 'elasticsearch_api_auth_tokentype', [
                'label' => t('Token type for the Authorization header'),
                'description' => t('API Token type for the Authorization header (default: Bearer)'),
                'value' => 'Bearer',
            ]);

            $this->addElement('password', 'elasticsearch_api_auth_tokenvalue', [
                'label' => t('Token for the Authorization header'),
                'description' => t('API Token for the Authorization header'),
                'renderPassword' => true,
                'required' => true,
            ]);
        }

        $this->addElement('checkbox', 'elasticsearch_api_auth_mtls', [
            'label' => t('Use client certificate (mTLS)'),
            'description' => t('Use client certificate (mTLS) for the connection'),
            'class' => 'autosubmit',
        ]);

        if (isset($formData['elasticsearch_api_auth_mtls']) && $formData['elasticsearch_api_auth_mtls'] === '1') {
            $this->addElement('text', 'elasticsearch_api_auth_mtls_cert', [
                'label' => t('mTLS client certificate path'),
                'description' => t('Path to the client certificate'),
                'required' => true,
            ]);
            $this->addElement('text', 'elasticsearch_api_auth_mtls_key', [
                'label' => t('mTLS client key path'),
                'description' => t('Path to the client key'),
                'required' => true,
            ]);
            $this->addElement('text', 'elasticsearch_api_auth_mtls_ca', [
                'label' => t('mTLS client CA path'),
                'description' => t('Path to the CA. Defaults to system CA'),
                'required' => false,
            ]);
        }

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

        $greaterThanValidator = new Zend_Validate_Callback(function ($value) {
            if ($value <= 0) {
                return false;
            }
            return true;
        });

        $greaterThanValidator->setMessage(
            $this->translate('The cannot be smaller than 1'),
            Zend_Validate_Callback::INVALID_VALUE
        );

        $this->addElement('number', 'elasticsearch_api_max_data_points', [
            'label' => t('The maximum numbers of datapoints each series returns'),
            'description' => t(
                'The maximum numbers of datapoints each series returns.'
                    . ' Only used in the OTLPMetricsWriter. The module will use this in the TBUCKET query downsample the data.'
            ),
            'required' => false,
            'placeholder' => 10000,
            'validators' => [$greaterThanValidator],
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
        $writer = $form->getValue('elasticsearch_icinga_writer', '');
        $index = $form->getValue('elasticsearch_api_index', 'icinga2');
        $tlsVerify = (bool) $form->getValue('elasticsearch_api_tls_insecure', false);
        $maxDataPoints = (int) $form->getValue('elasticsearch_api_max_data_points', 10000);
        // Auth values
        $authMethod = $form->getValue('elasticsearch_api_auth_method', 'none');
        $authTokenType = $form->getValue('elasticsearch_api_auth_tokentype', 'Bearer');
        $authTokenValue = $form->getValue('elasticsearch_api_auth_tokenvalue', '');
        $authUsername = $form->getValue('elasticsearch_api_auth_username', '');
        $authPassword = $form->getValue('elasticsearch_api_auth_password', '');
        // mTLS values
        $authMTLS = $form->getValue('elasticsearch_api_auth_mtls', false);
        $authMTLSCert = $form->getValue('elasticsearch_api_auth_mtls_cert', '');
        $authMTLSKey = $form->getValue('elasticsearch_api_auth_mtls_key', '');
        $authMTLSCA = $form->getValue('elasticsearch_api_auth_mtls_ca', '');

        $auth = [
            'method' => strtolower($authMethod),
            'tokentype' => $authTokenType,
            'tokenvalue' => $authTokenValue,
            'username' => $authUsername,
            'password' => $authPassword,
            'mtls' => $authMTLS,
            'mtls_cert' => $authMTLSCert,
            'mtls_key' => $authMTLSKey,
            'mtls_ca' => $authMTLSCA,
        ];

        if ($writer === 'ElasticsearchWriter') {
            $c = new ElasticsearchClient($baseURI, $maxDataPoints, $timeout, $tlsVerify, $index, $auth);
        } else {
            $c = new OTLPMetricsClient($baseURI, $maxDataPoints, $timeout, $tlsVerify, $index, $auth);
        }

        $status = $c->status();

        return $status;
    }
}
