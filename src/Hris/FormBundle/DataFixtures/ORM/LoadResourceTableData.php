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
namespace Hris\FormBundle\DataFixtures\ORM;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Hris\FormBundle\Entity\ResourceTable;
use Hris\FormBundle\DataFixtures\ORM\LoadFieldData;
use Hris\FormBundle\Entity\ResourceTableFieldMember;
use Symfony\Component\Stopwatch\Stopwatch;

class LoadResourceTableData implements FixtureInterface, ContainerAwareInterface, OrderedFixtureInterface
{
	/**
	 * {@inheritDoc}
	 * @see Doctrine\Common\DataFixtures.FixtureInterface::load()
	 */
    private $resourceTables;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * {@inheritDoc}
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * Returns array of form fixtures.
     *
     * @return mixed
     */
    public function getResourceTables()
    {
        return $this->resourceTables;
    }

    /**
     * Returns array of dummy forms
     * @return array
     */
    public function addDummyResourceTables()
    {
        // Load Public Data
        $this->resourceTables = Array(
            0=>Array(
                'name'=>'All Fields',
                'filter'=>false,
                'inputType'=>NULL,
                'compulsory'=> NULL,
                'description'=>'All Record Fields used',
            ),
            1=>Array(
                'name'=>'Combo Fields',
                'filter'=>true,
                'inputType'=>'Select',
                'compulsory'=>NULL,
                'description'=>'All Record Fields with select options',
            ),
            2=>Array(
                'name'=>'Compulsory Fields',
                'filter'=>true,
                'inputType'=>NULL,
                'compulsory'=>true,
                'description'=>'All Record Fields that are compulsory',
            ),
        );
        return $this->resourceTables;
    }

	public function load(ObjectManager $manager)
	{
        $logger = $this->container->get('logger');

        $stopwatch = new Stopwatch();
        $stopwatch->start('dummyResourceTableGeneration');
        // Populate dummy forms
        $this->addDummyResourceTables();
        // Seek dummy fields
        $loadFieldData = new LoadFieldData();
        $loadFieldData->addDummyFields();
        $dummyFields = $loadFieldData->getFields();

        foreach($this->resourceTables as $resourceTableKey=>$humanResourceResourceTable) {
            $resourceTable = new ResourceTable();
            $resourceTable->setName($humanResourceResourceTable['name']);
            $resourceTable->setDescription($humanResourceResourceTable['description']);
            $resourceTableRefernce = strtolower(str_replace(' ','',$humanResourceResourceTable['name'])).'-resourcetable';
            $this->addReference($resourceTableRefernce, $resourceTable);
            $manager->persist($resourceTable);
            // Add Field Members for the resource table created
            $sort=1;
            foreach($dummyFields as $key => $dummyField)
            {
                //Filter addition of fields not compliant to filter
                if($humanResourceResourceTable['filter'] ==false || $humanResourceResourceTable['inputType']==$dummyField['inputType'] || $humanResourceResourceTable['compulsory']==$dummyField['compulsory'] ) {
                    $resourceTableMember = new ResourceTableFieldMember();
                    $resourceTableMember->setField($manager->merge($this->getReference( strtolower(str_replace(' ','',$dummyField['name'])).'-field' )));
                    $resourceTableMember->setResourceTable( $manager->merge($this->getReference($resourceTableRefernce)) );
                    $resourceTableMember->setSort($sort++);
                    $referenceName = strtolower(str_replace(' ','',$humanResourceResourceTable['name']).str_replace(' ','',$dummyField['name'])).'-resourcetable-field-member';
                    $this->addReference($referenceName, $resourceTableMember);
                    $manager->persist($resourceTableMember);
                    $resourceTable->addResourceTableFieldMember($resourceTableMember);
                    unset($resourceTableMember);
                }
            }
            $manager->persist($resourceTable);
            unset($resourceTable);
        }
		$manager->flush();

        $dummyResourceTableGenerationLap = $stopwatch->lap('dummyResourceTableGeneration');
        $dummyResourceTableGenerationDuration = round(($dummyResourceTableGenerationLap->getDuration()/1000),2);

        if( $dummyResourceTableGenerationDuration <60 ) {
            $dummyResourceTableGenerationDurationMessage = round($dummyResourceTableGenerationDuration,2).' sec.';
        }elseif( $dummyResourceTableGenerationDuration >= 60 && $dummyResourceTableGenerationDuration < 3600 ) {
            $dummyResourceTableGenerationDurationMessage = round(($dummyResourceTableGenerationDuration/60),2) .' min.';
        }elseif( $dummyResourceTableGenerationDuration >=3600 && $dummyResourceTableGenerationDuration < 216000) {
            $dummyResourceTableGenerationDurationMessage = round(($dummyResourceTableGenerationDuration/3600),2) .' hrs';
        }else {
            $dummyResourceTableGenerationDurationMessage = round(($dummyResourceTableGenerationDuration/86400),2) .' days';
        }
        echo "\tDummy data schema generation complete in ".$dummyResourceTableGenerationDurationMessage."\n";

        // Generate resource tables
        $resourceTables = $manager->getRepository('HrisFormBundle:ResourceTable')->findAll();
        foreach($resourceTables as $resourceTableKey=>$resourceTable) {
            // Ugly hack to generate resource table for "All Fields" only
            if($resourceTable->getName() == "All Fields") {
                $success = $resourceTable->generateResourceTable($manager,$logger);
                $messageLog = $resourceTable->getMessageLog();
                if($success) echo $messageLog;
                else echo "Failed with:".$messageLog;
            }
        }
	}
	
	/**
     * The order in which this fixture will be loaded
	 * @return integer
	 */
	public function getOrder()
	{
        //LoadRecordData preceeds
		return 12;
	}

}
