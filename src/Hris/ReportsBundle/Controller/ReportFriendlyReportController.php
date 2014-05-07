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
namespace Hris\ReportsBundle\Controller;

use Hris\FormBundle\Entity\FriendlyReport;
use Hris\FormBundle\Entity\FriendlyReportCategory;
use Hris\OrganisationunitBundle\Entity\Organisationunit;
use Hris\FormBundle\Entity\Field;
use Hris\FormBundle\Entity\ResourceTable;
use Hris\RecordsBundle\Entity\Record;
use Hris\ReportsBundle\Form\ReportAggregationType;
use Hris\ReportsBundle\Form\ReportFriendlyReportType;
use Hris\ReportsBundle\Form\ReportFriendlyType;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Hris\ReportsBundle\Entity\Report;
use Hris\ReportsBundle\Form\ReportType;
use Ob\HighchartsBundle\Highcharts\Highchart;
use Zend\Json\Expr;
use JMS\SecurityExtraBundle\Annotation\Secure;

/**
 * Report Friendly Report controller for generation of friendlier
 * standard/generic reports.
 *
 * @Route("/reports/friendlyreport")
 */
class ReportFriendlyReportController extends Controller
{
    /**
     * @var array
     */
    private $friendlyReportValueCombinations;

    /**
     * @var integer
     */
    private $recursionIncriment;

    /**
     * @var array
     */
    private $currentGroupCombination;

    /**
     * @var array
     */
    private $reportCategories;

    /**
     * Show Report Aggregation
     *
     * @Secure(roles="ROLE_SUPER_USER,ROLE_REPORTFRIENDLYREPORT_GENERATE")
     * @Route("/", name="report_friendlyreport")
     * @Method("GET")
     * @Template()
     */
    public function indexAction()
    {

        $friendlyReportForm = $this->createForm(new ReportFriendlyReportType($this->getUser()),null,array('em'=>$this->getDoctrine()->getManager()));

        return array(
            'friendlyReportForm'=>$friendlyReportForm->createView(),
        );
    }

    /**
     * Generate friendly reports
     *
     * @Secure(roles="ROLE_SUPER_USER,ROLE_REPORTFRIENDLYREPORT_GENERATE")
     * @Route("/", name="report_friendlyreport_generate")
     * @Method("PUT")
     * @Template()
     */
    public function generateAction(Request $request)
    {
        $entityManager = $this->getDoctrine()->getManager();
        $friendlyReportForm = $this->createForm(new ReportFriendlyReportType($this->getUser()),null,array('em'=>$this->getDoctrine()->getManager()));
        $friendlyReportForm->bind($request);

        if ($friendlyReportForm->isValid()) {
            $friendlyReportFormData = $friendlyReportForm->getData();
            $friendlyReport = $friendlyReportFormData['genericReport'];
            $organisationunit = $friendlyReportFormData['organisationunit'];
            $targets = $friendlyReportFormData['targets'];
            $targetValues = $targets->getValues();
            $forms = $friendlyReportFormData['forms'];
            $organisationunitGroupset = (isset($friendlyReportFormData['organisationunitGroupset'])) ? $friendlyReportFormData['organisationunitGroupset'] : null;
            $organisationunitGroup = (isset($friendlyReportFormData['organisationunitGroup'])) ? $friendlyReportFormData['organisationunitGroup'] : null;
            // Create FormIds
            $formIds = NULL;
            foreach($forms as $formKey=>$formObject) {
                if(empty($formIds)) $formIds=$formObject->getId();else $formIds.=','.$formObject->getId();
            }
            // Create target columns if targets have been passed
            $targetColumns = NULL;
            if(isset($targets) && !empty($targets)) {
                foreach($targets as $targetKey=>$target) {
                    $targetColumns = empty($targetColumns) ? str_replace(' ','',$target->getName()) : $targetColumns.",".str_replace(' ','',$target->getName());
                }
            }
            // Create OrganisationunitGroupIds
            $organisationunitGroupIds = NULL;
            if(isset($organisationunitGroup) && !empty($organisationunitGroup)) {
                foreach($organisationunitGroup as $organisationunitGroupKey=>$organisationunitGroupObject) {
                    if(empty($organisationunitGroupIds)) $organisationunitGroupIds=$organisationunitGroupObject->getId();else $organisationunitGroupIds.=','.$organisationunitGroupObject->getId();
                }
            }
        }
        $title = $friendlyReport->getName().' for '. $organisationunit->getLongname().' and lower levels';







        if($friendlyReport->getType()=="sql") {
            $selectQuery = $friendlyReport->getSQL();

            // Get Standard Resource table name
            $resourceTableName = str_replace(' ','_',trim(strtolower(ResourceTable::getStandardResourceTableName())));
            $resourceTableAlias="ResourceTable";
            $organisationUnitJoinClause=" INNER JOIN hris_organisationunit as Organisationunit ON Organisationunit.id = $resourceTableAlias.organisationunit_id
                                            INNER JOIN hris_organisationunitstructure AS Structure ON Structure.organisationunit_id = $resourceTableAlias.organisationunit_id ";

            // Clause for filtering target organisationunits
            $organisationunitId = $organisationunit->getId();
            // With Lower Levels
            $organisationunit = $this->getDoctrine()->getManager()->getRepository('HrisOrganisationunitBundle:Organisationunit')->find($organisationunitId);
            $organisationunitLevelsWhereClause = " Structure.level".$organisationunit->getOrganisationunitStructure()->getLevel()->getLevel()."_id=$organisationunitId AND Structure.level_id >= ( SELECT hris_organisationunitlevel.level FROM hris_organisationunitstructure INNER JOIN hris_organisationunitlevel ON hris_organisationunitstructure.level_id=hris_organisationunitlevel.id  WHERE hris_organisationunitstructure.organisationunit_id=$organisationunitId ) ";
            // Clause for filtering target forms
            $formsWhereClause=" $resourceTableAlias.form_id IN ($formIds) ";
            $selectQuery = str_replace('#{organisationunitJoinClause}',$organisationUnitJoinClause,$selectQuery);
            $selectQuery = str_replace('#{organisationunitWhereClause}',$organisationunitLevelsWhereClause,$selectQuery);
            $selectQuery = str_replace('#{formWhereClause}',$formsWhereClause,$selectQuery);
            $sqlQueries = explode(';',$selectQuery);
            foreach($sqlQueries as $sqlKey=>$sqlQuery) {
                $friendlyReportResults = $this->getDoctrine()->getManager()->getConnection()->fetchAll($sqlQuery);
            }
            $adjustedColspan = NULL;
            $reversedRepetition = NULL;
            $target = NULL;
            $colspanCounter = NULL;
            $groupPositionCounter = NULL;
        }else {
            /*
         * Initializing query for friendly report calculation
         */
            // Get Standard Resource table name
            $resourceTableName = str_replace(' ','_',trim(strtolower(ResourceTable::getStandardResourceTableName())));
            $resourceTableAlias="ResourceTable";
            $organisationUnitJoinClause=" INNER JOIN hris_organisationunit as Organisationunit ON Organisationunit.id = $resourceTableAlias.organisationunit_id
                                            INNER JOIN hris_organisationunitstructure AS Structure ON Structure.organisationunit_id = $resourceTableAlias.organisationunit_id ";
            if(!empty($targetValues)) {
                $indicatorTargetJoinClause = " INNER JOIN hris_organisationunitgroup_members as OrganisationunitGroupMembers ON OrganisationunitGroupMembers.organisationunit_id=$resourceTableAlias.organisationunit_id
                                      INNER JOIN hris_organisationunitgroup as OrganisationunitGroup ON OrganisationunitGroup.id=OrganisationunitGroupMembers.organisationunitgroup_id
	                                  INNER JOIN hris_indicator_target as IndicatorTarget ON IndicatorTarget.organisationunitgroup_id=OrganisationunitGroup.id";
                $organisationUnitJoinClause .= $indicatorTargetJoinClause;
            }else {
                $indicatorTargetJoinClause = NULL;
            }
            $joinClause = $organisationUnitJoinClause;
            $fromClause=" FROM $resourceTableName $resourceTableAlias ";

            // Clause for filtering target organisationunits
            $organisationunitId = $organisationunit->getId();
            // With Lower Levels
            $organisationunit = $this->getDoctrine()->getManager()->getRepository('HrisOrganisationunitBundle:Organisationunit')->find($organisationunitId);
            $organisationunitLevelsWhereClause = " Structure.level".$organisationunit->getOrganisationunitStructure()->getLevel()->getLevel()."_id=$organisationunitId AND Structure.level_id >= ( SELECT hris_organisationunitlevel.level FROM hris_organisationunitstructure INNER JOIN hris_organisationunitlevel ON hris_organisationunitstructure.level_id=hris_organisationunitlevel.id  WHERE hris_organisationunitstructure.organisationunit_id=$organisationunitId ) ";
            // Clause for filtering target forms
            $formsWhereClause=" $resourceTableAlias.form_id IN ($formIds) ";

            // Query for Options to exclude from reports
            $fieldOptionsToSkip = $this->getDoctrine()->getManager()->getRepository('HrisFormBundle:FieldOption')->findBy (array('skipInReport' =>True));
            //filter the records with exclude report tag

            if($friendlyReport->getIgnoreSkipInReport() == False) {
                foreach($fieldOptionsToSkip as $key => $fieldOptionToSkip){
                    if(empty($fieldOptionsToSkipQuery)) {
                        $fieldOptionsToSkipQuery = "$resourceTableAlias.".$fieldOptionToSkip->getField()->getName()." !='".$fieldOptionToSkip->getValue()."'";
                    }else {
                        $fieldOptionsToSkipQuery .= " AND $resourceTableAlias.".$fieldOptionToSkip->getField()->getName()." !='".$fieldOptionToSkip->getValue()."'";
                    }
                }
            }

            // Deducing colspan for cells in the header
            $groupPositionCounter=0;$previousColspan= 1;$colspan = NULL;$repetition = NULL;
            // Row count for the entire database(rows accessible by user)
            // Combination of serie and category columns
            $seriesFieldName=$friendlyReport->getSerie()->getField()->getName();

            /*
             * Go through categories and construct viable columns for SQL
             */
            foreach($friendlyReport->getFriendlyReportCategory() as $friendlyReportCategoryKey=>$friendlyReportCategory) {
                if(!isset($pastFirstCategory)) $pastFirstCategory=True;// Initiate first category
                foreach($friendlyReportCategory->getFieldOptionGroup()->getFieldOption() as $fieldOptionKey=>$fieldOption ) {
                    $queryColumnNames[] = str_replace('-','_',str_replace(' ','',$fieldOption->getValue()));
                    $categoryFieldNames[] = $fieldOption->getField()->getName();
                    $categoryFieldName = $fieldOption->getField()->getName();
                    $categoryFieldOptionValue=str_replace('-','_',$fieldOption->getValue());
                    $categoryFieldOptionValues[]=str_replace('-','_',$fieldOption->getValue());
                    $categoryResourceTableName=$resourceTableAlias.str_replace(' ','',$categoryFieldOptionValue);
                    $queryColumnWhereClause[str_replace('-','_',$fieldOption->getValue())] = "$categoryResourceTableName.$categoryFieldName='".str_replace(' ','',$categoryFieldOptionValue)."'";
                }
            }

            foreach($queryColumnNames as $queryColumnNameKey=>$queryColumnName) {
                // construction of category column query in relation to serie
                // randomize resourcetable alias
                $categoryFieldName=$categoryFieldNames[$queryColumnNameKey];
                $categoryFieldOptionValue=$categoryFieldOptionValues[$queryColumnNameKey];
                $categoryResourceTableName=$resourceTableAlias.str_replace(' ','',$categoryFieldOptionValue);
                $joinClause .= " LEFT JOIN
                            (
                                SELECT COUNT($categoryResourceTableName.".str_replace(' ','',$categoryFieldName).") AS ".str_replace(' ','',$categoryFieldOptionValue).", $categoryResourceTableName.$seriesFieldName
                                FROM $resourceTableName $categoryResourceTableName
                                ".str_replace($resourceTableAlias,$categoryResourceTableName,$organisationUnitJoinClause)."
                                WHERE $categoryResourceTableName.$categoryFieldName='$categoryFieldOptionValue'
                                ".( !empty($fieldOptionsToSkipQuery) ? str_replace($resourceTableAlias,$categoryResourceTableName," AND ( $fieldOptionsToSkipQuery )") : "" ) ."
                                ".( !empty($organisationunitLevelsWhereClause) ? str_replace($resourceTableAlias,$categoryResourceTableName," AND ( $organisationunitLevelsWhereClause )") : "" ) ."
                                ".( !empty($formsWhereClause) ? str_replace($resourceTableAlias,$categoryResourceTableName," AND ( $formsWhereClause )") : "" ) ."
                                GROUP BY $categoryResourceTableName.$seriesFieldName
                            ) $categoryResourceTableName ON $categoryResourceTableName.$seriesFieldName= $resourceTableAlias.$seriesFieldName";
            }

            //Target join clause
            $targetJoinClause = NULL;
            if(isset($targets) && !empty($targets)) {
                foreach($targets as $targetKey=>$target) {
                    $targetColumn = str_replace(' ','',$target->getName());
                    $targetJoinClause .= " LEFT JOIN (
                                                    SELECT hris_fieldoption.value as ".$targetColumn."fieldOption,(
                                                        hris_indicator_targetfieldoption.value *
                                                        (
                                                            SELECT count(Organisationunit.id) FROM hris_organisationunit AS Organisationunit
                                                            INNER JOIN hris_organisationunitstructure AS Structure2 ON Structure2.organisationunit_id = Organisationunit.id
                                                            INNER JOIN hris_organisationunitgroup_members as OrganisationunitGroupMembers ON OrganisationunitGroupMembers.organisationunit_id=Organisationunit.id
                                                            INNER JOIN hris_organisationunitgroup as OrganisationunitGroup ON OrganisationunitGroup.id=OrganisationunitGroupMembers.organisationunitgroup_id
                                                            INNER JOIN hris_indicator_target as IndicatorTarget ON IndicatorTarget.organisationunitgroup_id=OrganisationunitGroup.id
                                                            WHERE ".str_replace('Structure','Structure2',$organisationunitLevelsWhereClause)."
                                                            AND IndicatorTarget.id=".$target->getId()."
                                                        )

                                                    ) as $targetColumn FROM hris_fieldoption
                                                    INNER JOIN hris_indicator_targetfieldoption ON hris_indicator_targetfieldoption.fieldoption_id=hris_fieldoption.id
                                                    INNER JOIN hris_indicator_target ON hris_indicator_target.id=hris_indicator_targetfieldoption.target_id
                                                    WHERE hris_indicator_target.id=".$target->getId()."
                                                    ORDER BY ".$targetColumn."fieldOption
                                        ) HrisTargetValues".$targetColumn." ON HrisTargetValues".$targetColumn.".".$targetColumn."fieldOption = $resourceTableAlias.$seriesFieldName";
                }
            }


            $columns = " DISTINCT $resourceTableAlias.$seriesFieldName as $seriesFieldName,".implode(',',$queryColumnNames).( !empty($targetColumns) ? ','.$targetColumns : '');
            if(!empty($targetJoinClause)) $joinClause .=$targetJoinClause;
            $selectQuery="SELECT $columns $fromClause $joinClause WHERE $organisationunitLevelsWhereClause".( !empty($fieldOptionsToSkipQuery) ? " AND ( $fieldOptionsToSkipQuery )" : "" );

            $friendlyReportResults = $this->getDoctrine()->getManager()->getConnection()->fetchAll($selectQuery);
        }



        foreach($friendlyReport->getFriendlyReportCategory() as $friendlyReportCategoryKey=>$friendlyReportCategory) {
            /**
             * Deduce measurements of table display on view page
             * i.e. column span, rowspan, and cell repetitions per column spans
             */
            $colspanCounter[$groupPositionCounter] = $friendlyReportCategory->getFieldOptionGroup()->getName();// Increment colspan
            if($groupPositionCounter==0) {
                $colspan[$friendlyReportCategory->getFieldOptionGroup()->getName()] = $previousColspan;
            }else {
                $colspan[$friendlyReportCategory->getFieldOptionGroup()->getName()] = $previousColspan*$friendlyReportCategory->getFieldOptionGroup()->getFieldOption()->count();
                $previousColspan= $colspan[$friendlyReportCategory->getFieldOptionGroup()->getName()];
            }
            $repetition[$friendlyReportCategory->getFieldOptionGroup()->getName()]=$friendlyReportCategory->getFieldOptionGroup()->getFieldOption()->count();
            $groupPositionCounter++;
        }
        $reverseCounter=0;
        for($colspanIncr=($groupPositionCounter-1);$colspanIncr>=0;$colspanIncr--) {
            /**
             * Calibrate measurements for columnspan and rowspan
             */
            $adjustedColspan[$colspanCounter[$reverseCounter]]=$colspan[$colspanCounter[$colspanIncr]];
            $reversedRepetition[$colspanCounter[$reverseCounter]]=$repetition[$colspanCounter[$colspanIncr]];
            $reverseCounter++;
        }
        $reversedRepetition[$colspanCounter[0]]=0; // no repetition for first row.

        return array(
            'friendlyReport'=>$friendlyReport,
            'title'=> $title,
            'colspan'=>$adjustedColspan,
            'repetition'=>$reversedRepetition,
            'friendlyReportResults'=>$friendlyReportResults,
            'targets'=>$targets,
        );
    }

    /**
     * @param null $groupIterator
     * @param null $group
     */
    public function populateGenericReportValues($groupIterator=NULL,$group=NULL) {
        if(empty($groupIterator)) {
            // Empty next group {serve 1st group }
            $group = $this->reportCategories;
            $groupIterator = $group->getIterator();
            $currentGroup = $groupIterator->current();
            $withDrawn=NULL;
            // Loop through options of the report category
            foreach($currentGroup->getFieldOption() as $key=> $fieldOption ) {
                //$this->currentGroupCombination[$currentGroup->getId()]=$fieldOption->getValue();
                $this->currentGroupCombination[$currentGroup->getId()]=$fieldOption->getId();
                //$this->currentGroupCombinationField[$currentGroup->getId()]=$fieldOption->getField()->getName();
                $this->currentGroupCombinationField[$currentGroup->getId()]=$fieldOption->getField()->getId();
                // Only escalde group if not withDrawn from previous recursion
                if($withDrawn!=True) {
                    if($groupIterator->valid()) $groupIterator->next();
                }
                if($groupIterator->valid()) {
                    // Not last Category, go to next category
                    $lastKey=$groupIterator->key();
                    populateGenericReportValues($groupIterator,$group);
                    // Withdrawing from Recursion under single combo (all groups have been passed through)
                    // reset group
                    $groupIterator->rewind();
                    $groupIterator->seek($lastKey);
                    $withDrawn=True;
                }else {
                    // Last CategoryGroup hence compute value
                    $this->friendlyReportValueCombinations[$this->recursionIncriment++]=implode(',',$this->currentGroupCombination);
                }
            }
        }else {
            // next group is set { serve the rest of groups }
            $currentGroup = $groupIterator->current();
            // Looping through options of the report category
            $withDrawn=NULL;
            foreach($currentGroup->getFieldOption() as $key=> $fieldOption ) {
                //$this->currentGroupCombination[$currentGroup->getId()]=$fieldOption->getValue();
                $this->currentGroupCombination[$currentGroup->getId()]=$fieldOption->getId();
                //$this->currentGroupCombinationField[$currentGroup->getId()]=$fieldOption->getField()->getName();
                $this->currentGroupCombinationField[$currentGroup->getId()]=$fieldOption->getField()->getId();
                // Only escalde group if not withDrawn from previous recursion
                if($withDrawn!=True) {
                    if($groupIterator->valid()) $groupIterator->next();
                }
                if($groupIterator->valid()) {
                    // Not last Category, go to next category
                    $lastKey=$groupIterator->key();
                    populateGenericReportValues($groupIterator,$group);
                    // Withdrawing from Recursion under single combo (all groups have been passed through)
                    // reset group
                    $groupIterator->rewind();
                    $groupIterator->seek($lastKey);
                    $withDrawn=True;
                }else {
                    // Last CategoryGroup hence compute value
                    $this->friendlyReportValueCombinations[$this->recursionIncriment++]= implode(',',$this->currentGroupCombination);
                }
            }
        }

    }

}
