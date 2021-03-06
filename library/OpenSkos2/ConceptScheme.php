<?php

/*
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

namespace OpenSkos2;

use OpenSkos2\Rdf\Resource;
use OpenSkos2\Namespaces\Rdf;
use OpenSkos2\Namespaces\Skos;
use OpenSkos2\Rdf\Uri;
use OpenSkos2\Namespaces\DcTerms;

class ConceptScheme extends Resource
{

    const TYPE = Skos::CONCEPTSCHEME;

    /**
     * Resource constructor.
     * @param string $uri
     */
    public function __construct($uri = null)
    {
        parent::__construct($uri);
        $this->addProperty(Rdf::TYPE, new Uri(self::TYPE));
    }

   
   
    public function getDescription()
    {
        if ($this->hasProperty(DcTerms::DESCRIPTION)) {
            return (string) $this->getPropertySingleValue(DcTerms::DESCRIPTION);
        } else {
            return null;
        }
    }

    /**
     * Builds the path to the concept scheme icon.
     * Returns empty string if the file does not exist.
     *
     * @todo Moved from Editor_Models_ConceptScheme for backwards compatibility,
     * refactor later to not depend on the zend application
     * @param srtring $uuid
     * @param OpenSKOS_Db_Table_Row_Tenant $tenant optional, Default null.
     * If not set the currently logged one will be used.
     * @return string
     */
    public function getIconPath($tenant = null)
    {
        return self::buildIconPath($this->getUuid(), $tenant);
    }

    /**
     *
     * Builds the path to the concept scheme icon.
     * Returns empty string if the file does not exist.
     *
     * @todo Moved from Editor_Models_ConceptScheme for backwards compatibility,
     * refactor later to not depend on the zend application
     * @param srtring $uuid
     * @param \OpenSkos2\Tenant $tenant optional, Default null.
     * If not set the currently logged one will be used.
     * @return string
     */
    public static function buildIconPath($uuid, $tenant = null)
    {
        $editorOptions = \OpenSKOS_Application_BootstrapAccess::getBootstrap()->getOption('editor');

        if (null === $tenant) {
            $tenant = \OpenSkos2\TenantManager::getLoggedInTenant();
            $tenantCode = $tenant->getCode();
        } elseif (gettype($tenant) === 'string') {
            $tenantCode = $tenant;
        } else {
            $tenantCode = $tenant->getCode();
        }

        $ap = APPLICATION_PATH;
        // We always need tenant for getting icon path.
        if (null !== $tenant) {
            if (isset($editorOptions['schemeIcons']) && isset($editorOptions['schemeIcons']['assignPath'])) {
                $iconsAssignPath = $ap . $editorOptions['schemeIcons']['assignPath'] . '/' . $tenantCode;
            } else {
                $iconsAssignPath = $ap . \Editor_Forms_UploadIcon::DEFAULT_ASSIGN_PATH . '/' . $tenantCode;
            }

            if (isset($editorOptions['schemeIcons']) && isset($editorOptions['schemeIcons']['assignHttpPath'])) {
                $iconsAssignHttpPath = $editorOptions['schemeIcons']['assignHttpPath'] . '/' . $tenantCode;
            } else {
                $iconsAssignHttpPath = \Editor_Forms_UploadIcon::DEFAULT_ASSIGN_HTTP_PATH . '/' . $tenantCode;
            }

            if (isset($editorOptions['schemeIcons']) && isset($editorOptions['schemeIcons']['extension'])) {
                $iconsExtension = $editorOptions['schemeIcons']['extension'];
            } else {
                $iconsExtension = 'png';
            }

            if (is_file($iconsAssignPath . '/' . $uuid . '.' . $iconsExtension)) {
                return $iconsAssignHttpPath . '/' . $uuid . '.' . $iconsExtension . '?nocache=' . time();
            } else {
                return '';
            }
        } else {
            return '';
        }
    }
}
