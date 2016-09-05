<?php

namespace TwitterBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class DefaultController extends Controller
{
    public function indexAction()
    {
        return $this->render('TwitterBundle:Default:index.html.twig');
    }
}
