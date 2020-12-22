<?php

/**
 * OpenSKOS
 *
 * LICENSE
 *
 * This source file is subject to the GPLv3 license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.txt
 *
 * @category   OpenSKOS
 * @package    OpenSKOS
 * @copyright  Copyright (c) 2015 Picturae (http://www.picturae.com)
 * @author     Picturae
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GPLv3
 */
namespace OpenSkos2\Concept;

use OpenSkos2\Namespaces\SkosXl;
use OpenSkos2\Concept;
use OpenSkos2\Rdf\Uri;
use OpenSkos2\Rdf\Literal;
use OpenSkos2\SkosXl\LabelManager;
use OpenSkos2\SkosXl\Label;
use OpenSkos2\SkosXl\LabelCollection;
use OpenSkos2\Exception\OpenSkosException;
use OpenSkos2\Exception\TenantNotFoundException;
use OpenSkos2\Tenant;

class LabelHelper
{
    /**
     * @var LabelManager
     */
    protected $labelManager;

    /**
     * @param LabelManager $labelManager
     */
    public function __construct(LabelManager $labelManager)
    {
        $this->labelManager = $labelManager;
    }

    /**
     * Dump down all xl labels to simple labels.
     * Create xl label for each simple label which is not already presented as xl label.
     * @param Concept &$concept
     * @param bool $forceCreationOfXl , optional, Default: false
     * @throws OpenSkosException
     */
    public function assertLabels(Concept &$concept, $forceCreationOfXl = false)
    {

        /* @var $tenant \OpenSkos2\Tenant */
        $tenantCode = $concept->getTenant();
        $tenant = $this->labelManager->fetchByUuid($tenantCode->getValue(), \OpenSkos2\Tenant::TYPE, 'openskos:code');

        if (empty($tenant)) {
            throw new TenantNotFoundException(
                'Could not determine tenant for concept.'
            );
        }

        $useXlLabels = $tenant->isEnableSkosXl();

        foreach (Concept::$labelsMap as $xlLabelProperty => $simpleLabelProperty) {
            $fullXlLabels = [];
            foreach ($concept->getProperty($xlLabelProperty) as $labelValue) {
                if (!$labelValue instanceof Uri) {
                    throw new OpenSkosException(
                        'Not a valid xl label provided.'
                    );
                }

                if ($labelValue instanceof Label) {
                    if ($labelValue->isUriTempGenerated()) {
                        $labelValue->setUri(Label::generateUri());
                    }
                    $fullXlLabels[] = $labelValue;
                    continue;
                }

                $labelExists = $this->labelManager->askForUri($labelValue->getUri());

                if (!$labelExists && !($labelValue instanceof Label)) {
                    throw new OpenSkosException(
                        'The label ' . $labelValue . ' is not a fully described label resource '
                        . 'and does not exist in the system.'
                    );
                }

                $fullXlLabels[] = $this->labelManager->fetchByUri($labelValue);
            }

            // Extract all literals to compare agains simple labels
            $xlLabelsLiterals = [];
            foreach ($fullXlLabels as $label) {
                $xlLabelsLiterals[] = $label->getPropertySingleValue(SkosXl::LITERALFORM);
            }

            // Create xl label for any simple label which does not have matching one.
            // Do this only if skos xl labels are disabled, i.e. simple labels are primary.
            if ($useXlLabels === false || $forceCreationOfXl) {
                foreach ($concept->getProperty($simpleLabelProperty) as $simpleLabel) {
                    if (!$simpleLabel->isInArray($xlLabelsLiterals)) {
                        $label = new Label(Label::generateUri());
                        $label->setProperty(SkosXl::LITERALFORM, $simpleLabel);
                        $tenantCode = $concept->getTenant()->getValue();
                        $tenant = $this->labelManager->fetchByUuid(
                            $tenantCode,
                            \OpenSkos2\Tenant::TYPE,
                            'openskos:code'
                        );
                        $label->ensureMetadata($tenant);
                        $concept->addProperty($xlLabelProperty, $label);

                        $xlLabelsLiterals[] = $simpleLabel;
                    }
                }
            }
            $concept->setProperties($simpleLabelProperty, $xlLabelsLiterals);
        }
    }

	/**
	 * Determine if we really do have two copies of the same lable
	 *
	 * @param Label $label1
	 * @param Label $label2
	 * @return bool
	 */
	public static function doLabelsMatch(Label $label1, Label $label2)
	{
		$matching = true;
		if($matching && $label1->getUri() !== $label2->getUri()){
			$matching = false;
		}

		$lit1 = $label1->getProperty(SkosXL::LITERALFORM);
		$lit2 = $label2->getProperty(SkosXL::LITERALFORM);

		if($matching && count($lit1) === 1 && count($lit2) === 1){
			//Don't feel like working out the logic for multiple lables,
			// considering this is for a customer who doesn't use them
			if( $lit1[0]->getLanguage() !== $lit2[0]->getLanguage() ||
				$lit1[0]->getValue() !== $lit2[0]->getValue()){
				$matching = false;
			}
		}
		else{
			$matching = false;
		}
		return $matching;
	}

	/**
	 * Determine if we really need to delete/add a concept
	 *
	 * @param $insertAndDelete
	 * @return mixed
	 */
	public static function getLabelsUnitOfWork($insertAndDelete)
	{
		$list_dels = [];
		$list_ins = [];

		foreach ($insertAndDelete['delete'] as $del_key => $del_val) {
			foreach ($insertAndDelete['insert'] as $ins_key => $ins_val) {
				if(self::doLabelsMatch($del_val, $ins_val)){
					$list_ins[] = $ins_key;
					$list_dels[] = $del_key;
				}
			}
		}
		foreach ($list_dels as $key) {
			if(isset($insertAndDelete['delete'][$key])) {
				//php.net says unsetting an unset variable shouldn't throw an error! But it did!
				unset($insertAndDelete['delete'][$key]);
			}
		}
		foreach ($list_ins as $key) {
			if(isset($insertAndDelete['insert'][$key])) {
				unset($insertAndDelete['insert'][$key]);
			}
		}
		return $insertAndDelete;
	}

    /**
     * Insert any xl labels for the concept which do not exist yet.
     * Meant to be called together with insert of the concept.
     * @param Concept $concept
     * @param bool $returnOnly , optional, default: false.
     *  Set to true if the labels have to be returned only. Not inserted. Existing labels still will be deleted.
     * @throws OpenSkosException
     */
    public function insertLabels(Concept $concept)
    {
        $insertAndDelete = $this->getLabelsForInsertAndDelete($concept);

		$insertAndDelete = self::getLabelsUnitOfWork($insertAndDelete);

        $this->labelManager->setIsNoCommitMode(true);
        foreach ($insertAndDelete['delete'] as $deleteLabel) {
            $this->labelManager->delete($deleteLabel);
        }

        $this->labelManager->insertCollection($insertAndDelete['insert']);

        /*
         * Solr doesn't like multiple commits. Postpone the commit
        $this->labelManager->commit();
        $this->labelManager->setIsNoCommitMode(false);
        */
    }

    /**
     * Gets collections of labels to insert and to delete.
     * @param Concept $concept
     * @return ['delete' => $deleteLabels, 'insert' => LabelCollection]
     * @throws OpenSkosException
     */
    public function getLabelsForInsertAndDelete($concept)
    {
        $deleteLabels = new LabelCollection([]);
        $insertlabels = new LabelCollection([]);

        foreach (array_keys(Concept::$labelsMap) as $xlLabelProperty) {
            // Loop through xl labels
            foreach ($concept->getProperty($xlLabelProperty) as $label) {
                if (!$label instanceof Uri) {
                    throw new OpenSkosException(
                        'Not a valid xl label provided.'
                    );
                }

                $labelExists = $this->labelManager->askForUri($label->getUri());
				$jenaData = $this->labelManager->fetchByUri($label->getUri());

				//Which literals do we have to delete?
				$literalsJena = $jenaData->getProperty(SkosXl::LITERALFORM);
				$literalsWrite = $label->getProperty(SkosXl::LITERALFORM);
				foreach($literalsJena as $jena){
					$must_delete = true;
					foreach($literalsWrite as $write) {
						if( $jena->getLanguage() === $write->getLanguage() &&
							$jena->getValue() === $write->getValue()){
							$must_delete = false;
						}
					}
					if($must_delete){
						$this->labelManager->deleteMatchingTriples(
							sprintf("<%s>", $label->getUri()),
							SkosXl::LITERALFORM,
							$jena->getLanguage() ?
								sprintf('"%s"@%s', $jena->getValue(), $jena->getLanguage()) :
								sprintf('"%s"', $jena->getValue())

						);
					}
				}

				if (!$labelExists && !($label instanceof Label)) {
                    throw new OpenSkosException(
                        'The label ' . $label . ' is not a fully described label resource '
                        . 'and does not exist in the system.'
                    );
                }

                if (!$label instanceof Label) {
                    continue; // It is just an uri - nothing to do with it.
                }

                $tenantCode = $concept->getTenant();
                $tenant = $this->labelManager->fetchByUuid(
                    $tenantCode->getValue(),
                    \OpenSkos2\Tenant::TYPE,
                    'openskos:code'
                );
                $label->ensureMetadata($tenant);

                // Fetch, insert or replace label
                if ($labelExists) {
                    $deleteLabels->append($label);
                }

                $insertlabels->append($label);
            }
        }

        return [
            'delete' => $deleteLabels,
            'insert' => $insertlabels,
        ];
    }

    /**
     * Creates a new label using the parameters and inserts it into the DB
     * @param string $literalForm
     * @param string $language
     * @param Tenant $tenant
     * @return Label
     * @throws OpenSkosException
     */
    public function createNewLabel($literalForm, $language, Tenant $tenant)
    {
        if (empty($literalForm) || empty($language) || empty($tenant)) {
            throw new OpenSkosException('LiteralForm Language and Tenant must be specified when creating a new label.');
        }

        $rdfLiteral = new Literal($literalForm);
        $rdfLiteral->setLanguage($language);

        $label = new Label(Label::generateUri());
        $label->addProperty(SkosXl::LITERALFORM, $rdfLiteral);
        $label->ensureMetadata($tenant);
        $this->labelManager->insert($label);

        return $label;
    }
}
