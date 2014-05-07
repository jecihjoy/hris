<?php
/*
 *
 * Copyright 2012 Human Resource Information System
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301, USA.
 *
 * @since 2012
 * @author John Francis Mukulu <john.f.mukulu@gmail.com>
 *
 */
namespace Hris\ImportExportBundle\Controller;

use Hris\RecordsBundle\Entity\Record;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Hris\ImportExportBundle\Form\ExportMetadataType;
use JMS\SecurityExtraBundle\Annotation\Secure;

/**
 * ExportMetadata controller.
 *
 * @Route("/importexport/exportmetadata")
 */
class ExportMetadataController extends Controller
{

    /**
     * Lists all ExportMetadata entities.
     *
     * @Secure(roles="ROLE_SUPER_USER,ROLE_EXPORTMETADATA_LIST")
     * @Route("/", name="importexport_exportmetadata")
     * @Route("/list", name="importexport_exportmetadata_list")
     * @Method("GET")
     * @Template()
     */
    public function indexAction()
    {
        $exportMetadataForm = $this->createForm(new ExportMetadataType(),null,array('em'=>$this->getDoctrine()->getManager()));
        $exportMetadataForm->get('withLowerLevels')->setData(True);

        return array(
            'exportMetadataForm'=>$exportMetadataForm->createView(),
        );
    }
    /**
     * Creates a new ExportMetadata entity.
     *
     * @Secure(roles="ROLE_SUPER_USER,ROLE_EXPORTMETADATA_CREATE")
     * @Route("/{_format}", requirements={"_format"="json|"}, defaults={"_format"="json"}, name="importexport_exportmetadata_create")
     * @Method("POST")
     * @Template("HrisImportExportBundle:ExportMetadata:export.json.twig")
     */
    public function createAction(Request $request,$_format="json")
    {
        $serializer = $this->container->get('serializer');

        $exportMetadataForm = $this->createForm(new ExportMetadataType(),null,array('em'=>$this->getDoctrine()->getManager()));
        $exportMetadataForm->bind($request);

        if ($exportMetadataForm->isValid()) {
            $exportMetadataFormData = $exportMetadataForm->getData();
            $organisationunit = $exportMetadataFormData['organisationunit'];
            $forms = $exportMetadataFormData['forms'];
            $withLowerLevels = $exportMetadataFormData['withLowerLevels'];
        }
        /*
         * Generate an export file with data records
         */
        $formIds = Array();
        foreach($forms as $formKey=>$form) {
            $formIds[] = $form->getId();
            foreach ($form->getFormFieldMember() as $key => $fieldObject) {
                if( !$fieldObject->getField()->getIsCalculated() ) {
                    if(empty($formFieldMemberIds)) $formFieldMemberIds  = $fieldObject->getField()->getId();
                    else $formFieldMemberIds .=','.$fieldObject->getField()->getId();
                }
            }
        }
        $em = $this->getDoctrine()->getManager();
        $queryBuilder = $this->getDoctrine()->getManager()->createQueryBuilder();

        $userManager = $this->container->get('fos_user.user_manager');
        $user = $userManager->findUserByUsername($this->getUser());


        //Prepare field Option map, converting from stored FieldOption key in record value array to actual text value
        $fieldOptions = $this->getDoctrine()->getManager()->getRepository('HrisFormBundle:FieldOption')->findAll();
        foreach ($fieldOptions as $fieldOptionKey => $fieldOption) {
            $recordFieldOptionKey = ucfirst(Record::getFieldOptionKey());
            $fieldOptionMap[call_user_func_array(array($fieldOption, "get${recordFieldOptionKey}"),array()) ] =   $fieldOption->getValue();
        }

        //Prepare field map, converting from stored FieldOption key in record value array to actual text value
        $fields = $this->getDoctrine()->getManager()->getRepository('HrisFormBundle:Field')->findAll();
        foreach ($fields as $fieldKey => $field) {
            $recordFieldKey = ucfirst(Record::getFieldKey());
            $fieldMap[$field->getName()] =  call_user_func_array(array($field, "get${recordFieldKey}"),array());
        }

        $records = $queryBuilder->select('  record.id id,
                                            organisationunit.id organisationunit_id,
                                            form.id form_id,
                                            record.uid,
                                            record.instance,
                                            record.value,
                                            record.complete,
                                            record.correct,
                                            record.hashistory,
                                            record.hastraining,
                                            record.datecreated,
                                            record.lastupdated,
                                            record.username')
            ->from('HrisRecordsBundle:Record','record')
            ->join('record.organisationunit','organisationunit')
            ->join('record.form','form')
            ->join('organisationunit.organisationunitStructure','organisationunitStructure')
            ->join('organisationunitStructure.level','organisationunitLevel')
            ->andWhere('organisationunit.active=True');

        if($withLowerLevels) {
            $records = $records->andWhere('organisationunitLevel.level >= (
                                            SELECT selectedOrganisationunitLevel.level
                                            FROM HrisOrganisationunitBundle:OrganisationunitStructure selectedOrganisationunitStructure
                                            INNER JOIN selectedOrganisationunitStructure.level selectedOrganisationunitLevel
                                            WHERE selectedOrganisationunitStructure.organisationunit=:selectedOrganisationunit )'
                )
                ->andWhere('organisationunitStructure.level'.$organisationunit->getOrganisationunitStructure()->getLevel()->getLevel().'Organisationunit=:levelId');
        }else {
            $records = $records->andWhere('organisationunit.id='.$organisationunit->getId());
        }

        $records = $records->andWhere($queryBuilder->expr()->in('form.id',':formIds'))
            ->setParameters(array(
                'levelId'=>$organisationunit->getId(),
                'selectedOrganisationunit'=>$organisationunit->getId(),
                'formIds'=>$formIds,
            ))
            ->getQuery()->getResult();

        //Field Details
        $fields = $this->getDoctrine()->getManager()
            ->createQueryBuilder()
            ->select(' field.id,
                     field.uid,
                     dataType.id datatype_id,
                     inputType.id inputtype_id,
                     field.name,
                     field.compulsory,
                     field.isUnique,
                     field.datecreated,
                     field.lastupdated')
            ->from('HrisFormBundle:Field','field')
            ->join('field.dataType','dataType')
            ->join('field.inputType','inputType')
            ->getQuery()->getResult();
        //Field Option Details
        $fieldOptions = $this->getDoctrine()->getManager()
            ->createQueryBuilder()
            ->select('fieldOption.id,
                     fieldOption.value,
                     field.id field_id,
                     fieldOption.datecreated,
                     fieldOption.lastupdated')
            ->from('HrisFormBundle:FieldOption','fieldOption')
            ->join('fieldOption.field','field')->getQuery()->getResult();
        //Organisationunit details
        $organisationunits = $this->getDoctrine()->getManager()->createQueryBuilder()->select('organisationunit.uid organisationunit_uid,
                                            organisationunit.longname organisationunit_longname,
                                            organisationunit.datecreated organisationunit_datecreated,
                                            organisationunit.lastupdated organisationunit_lastupdated')
            ->from('HrisOrganisationunitBundle:Organisationunit','organisationunit')
            ->join('organisationunit.organisationunitStructure','organisationunitStructure')
            ->join('organisationunitStructure.level','organisationunitLevel')
            ->andWhere('organisationunit.active=True');
        if($withLowerLevels) {
            $organisationunits = $organisationunits->andWhere('organisationunitLevel.level >= (
                                            SELECT selectedOrganisationunitLevel.level
                                            FROM HrisOrganisationunitBundle:OrganisationunitStructure selectedOrganisationunitStructure
                                            INNER JOIN selectedOrganisationunitStructure.level selectedOrganisationunitLevel
                                            WHERE selectedOrganisationunitStructure.organisationunit=:selectedOrganisationunit )'
            )
                ->andWhere('organisationunitStructure.level'.$organisationunit->getOrganisationunitStructure()->getLevel()->getLevel().'Organisationunit=:levelId');
        }else {
            $organisationunits = $organisationunits->andWhere('organisationunit.id='.$organisationunit->getId());
        }
        $organisationunits = $organisationunits->setParameters(array(
                                                        'levelId'=>$organisationunit->getId(),
                                                        'selectedOrganisationunit'=>$organisationunit->getId()
                                                    ))
        ->getQuery()->getResult();

        $dataexport = Array(
            'hris_organisationunit'=>$organisationunits,
            'hris_field'=>$fields,
            'hris_fieldoption'=>$fieldOptions,
            'hris_record'=>$records
        );

        $serializer = $this->container->get('serializer');
        return array(
            'records' => $serializer->serialize($dataexport,empty($_format) ? "json" : $_format)
        );
    }

    /**
     * Displays a form to create a new ExportMetadata entity.
     *
     * @Secure(roles="ROLE_SUPER_USER,ROLE_EXPORTMETADATA_CREATE")
     * @Route("/new", name="importexport_exportmetadata_new")
     * @Method("GET")
     * @Template()
     */
    public function newAction()
    {
        $form   = $this->createForm(new ExportMetadataType(), null);

        return array(
            'form'   => $form->createView(),
        );
    }

    /**
     * Finds and displays a ExportMetadata entity.
     *
     * @Secure(roles="ROLE_SUPER_USER,ROLE_EXPORTMETADATA_SHOW")
     * @Route("/{id}", requirements={"id"="\d+"}, name="importexport_exportmetadata_show")
     * @Method("GET")
     * @Template()
     */
    public function showAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $entity = $em->getRepository('HrisImportExportBundle:ExportMetadata')->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find ExportMetadata entity.');
        }

        $deleteForm = $this->createDeleteForm($id);

        return array(
            'entity'      => $entity,
            'delete_form' => $deleteForm->createView(),
        );
    }

    /**
     * Displays a form to edit an existing ExportMetadata entity.
     *
     * @Secure(roles="ROLE_SUPER_USER,ROLE_EXPORTMETADATA_UPDATE")
     * @Route("/{id}/edit", name="importexport_exportmetadata_edit")
     * @Method("GET")
     * @Template()
     */
    public function editAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        $deleteForm = $this->createDeleteForm($id);

        return array(
            'delete_form' => $deleteForm->createView(),
        );
    }

    /**
     * Edits an existing ExportMetadata entity.
     *
     * @Secure(roles="ROLE_SUPER_USER,ROLE_EXPORTMETADATA_UPDATE")
     * @Route("/{id}", name="importexport_exportmetadata_update")
     * @Method("PUT")
     * @Template("HrisImportExportBundle:ExportMetadata:edit.html.twig")
     */
    public function updateAction(Request $request, $id)
    {
        $em = $this->getDoctrine()->getManager();

        $deleteForm = $this->createDeleteForm($id);
        $editForm = $this->createForm(new ExportMetadataType(), null);
        $editForm->bind($request);

        if ($editForm->isValid()) {

            return $this->redirect($this->generateUrl('importexport_exportmetadata'));
        }

        return array(
            'edit_form'   => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
        );
    }
    /**
     * Deletes a ExportMetadata entity.
     *
     * @Secure(roles="ROLE_SUPER_USER,ROLE_EXPORTMETADATA_DELETE")
     * @Route("/{id}", name="importexport_exportmetadata_delete")
     * @Method("DELETE")
     */
    public function deleteAction(Request $request, $id)
    {
        $form = $this->createDeleteForm($id);
        $form->bind($request);

        return $this->redirect($this->generateUrl('export'));
    }

    /**
     * Creates a form to delete a ExportMetadata entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder(array('id' => $id))
            ->add('id', 'hidden')
            ->getForm()
        ;
    }
}
