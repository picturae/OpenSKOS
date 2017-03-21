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

class Api_ConceptSchemeController extends OpenSKOS_Rest_Controller
{
    public function init()
    {
        parent::init();

        $this->_helper->contextSwitch()
                ->initContext($this->getRequestedFormat());

        if ('html' == $this->_helper->contextSwitch()->getCurrentContext()) {
            $this->getHelper('layout')->enableLayout();
        } else {
            $this->getHelper('layout')->disableLayout();
        }
        
        $this->getHelper('viewRenderer')->setNoRender(true);
    }
    
    public function postAction()
    {
        $this->_501('POST');
    }

    public function putAction()
    {
        $this->_501('PUT');
    }

    public function deleteAction()
    {
        $this->_501('DELETE');
    }
    
    public function getAction()
    {
        $this->_501('GET');
    }
    
    /**
     * @apiVersion 1.0.0
     * @apiDescription Find a SKOS Concept scheme
     * The following requests are possible
     *
     * /api/concept-scheme?q=doood
     *
     * /api/concept-scheme?q=do*
     *
     * /api/concept-scheme?q=prefLabel:dood
     *
     * /api/concept-scheme?q=do* status:approved
     *
     * /api/concept-scheme?q=prefLabel:do*&rows=0
     *
     * /api/concept-scheme?q=prefLabel@nl:doo
     *
     * /api/concept-scheme?q=prefLabel@nl:do*
     *
     * /api/concept-scheme?q=do*&tenant=beng&collection=gtaa
     *
     * @api {get} /api/concept-scheme Concept scheme
     * @apiName Concept Scheme
     * @apiGroup Concept Scheme
     * @apiParam {String} q search term
     * @apiParam {String} rows Number of rows to return
     * @apiParam {String} fl List of fields to return
     * @apiParam {String} tenant Name of the tenant to query. Default is all tenants
     * @apiParam {String} collection OpenSKOS set to query. Default is all sets
     * @apiSuccess (200) {String} XML
     * @apiSuccessExample {String} Success-Response
     *   HTTP/1.1 200 Ok
     *   
     *    @TODO
     *
     */
    public function indexAction()
    {
        if (null === ($q = $this->getRequest()->getParam('q'))) {
            $this->getResponse()
                    ->setHeader('X-Error-Msg', 'Missing required parameter `q`');
            throw new Zend_Controller_Exception('Missing required parameter `q`', 400);
        }

        $this->getHelper('layout')->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);

        $conceptScheme =$this->getDI()->make('OpenSkos2\Api\ConceptScheme');

        $context = $this->_helper->contextSwitch()->getCurrentContext();
        $request = $this->getPsrRequest();
        $response = $conceptScheme->index($request, $context);
        $this->emitResponse($response);
    }
}
