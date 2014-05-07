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
namespace Hris\UserBundle\Form;
use Doctrine\ORM\Mapping\UniqueConstraint;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntityValidator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Yaml\Parser;

class UserNewType extends AbstractType
{
    /**
     * Generates an array of roles based on roles stipulated in security configurations
     * @return mixed
     */
    private function getRoleNames()
    {
        $pathToSecurity = __DIR__ . '/../../../..' . '/app/config/security.yml';
        $yaml = new Parser();
        $userRoles = array();
        $rolesArray = $yaml->parse(file_get_contents($pathToSecurity));
        $rolesCaptured = $rolesArray['security']['role_hierarchy'];
        //print_r($rolesCaptured);
        foreach($rolesCaptured as $key=>$value) {
            $userRoles[]=$key;
            if(!is_array($value)) {
                $userRoles[]=$value;
            }else {
                $userRoles=array_merge($userRoles,$value);
            }
        }
        $userRoles = array_unique($userRoles);
        //sort for display purposes
        asort($userRoles);
        foreach($userRoles as $key=>$value) {
            $sortedRoles[$value]=$value;
        }
        return $sortedRoles;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        // assuming $entityManager is passed in options
        $em = $options['em'];
        $transformer = new OrganisationunitToIdTransformer($em);

        $builder
            ->add('username', null, array(
                'label' => 'form.username',
                'translation_domain' => 'FOSUserBundle',
                'constraints'=>array(
                    new NotBlank(),
                    new UniqueConstraint('columns',array('username','email')),
                )
            ))
            ->add('email', 'email', array(
                'label' => 'form.email',
                'translation_domain' => 'FOSUserBundle',
                'constraints'=> Array (
                    new UniqueEntity(array(
                        'fields'=>array('username'),
                        'groups'=>array('registration'),
                    )),
                )
            ))
            ->add('plainPassword', 'repeated', array(
                'type' => 'password',
                'required'=> true,
                'options' => array('translation_domain' => 'FOSUserBundle'),
                'first_options' => array('label' => 'form.password'),
                'second_options' => array('label' => 'form.password_confirmation'),
                'invalid_message' => 'fos_user.password.mismatch',
            ))
            ->add($builder->create('organisationunit','hidden',array(
                    'required'=>True,
                    'constraints'=> array(
                        new NotBlank(),
                    )
                ))->addModelTransformer($transformer)
            )
            ->add('phonenumber')
            ->add('jobTitle')
            ->add('firstName',null,array(
                'required'=>True,
            ))
            ->add('middleName')
            ->add('surname',null,array(
                'required'=>True,
            ))
            ->add('form')
            ->add('enabled',null,array(
                'required'=>false,
            ))
            ->add('locked',null,array(
                'required'=>false,
            ))
            ->add('expiresAt','date',array(
                'required'=>false,
                'widget' => 'single_text',
                'format' => 'dd/MM/yyyy',
                'attr' => array('class' => 'date')
            ))
            ->add('credentialsExpireAt','date',array(
                'required'=>false,
                'widget' => 'single_text',
                'format' => 'dd/MM/yyyy',
                'attr' => array('class' => 'date')
            ))
            ->add('roles', 'choice', array(
                'multiple'=>true,
                'choices'   => $this->getRoleNames(),
            ))
            ->add('groups',null,array(
                'required'=>False,
            ))
        ;
    }

    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(
            array(
            'data_class' => 'Hris\UserBundle\Entity\User'
            ));
        $resolver->setRequired(
            array('em')
        );
        $resolver->setAllowedTypes(array(
            'em'=>'Doctrine\Common\Persistence\ObjectManager',
        ));
    }

    public function getName()
    {
        return 'hris_userbundle_usertype';
    }
}
