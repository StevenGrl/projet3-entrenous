<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Event;
use AppBundle\Entity\Tag;
use AppBundle\Service\CheckUserRole;
use AppBundle\Service\CheckDistance;
use AppBundle\Service\Mailer;
use AppBundle\Service\SlugService;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;

/**
 * Event controller.
 *
 * @Route("event")
 */
class EventController extends Controller
{
    /**
     * Lists all event entities.
     *
     * @Route("/", name="event_index")
     * @Method("GET")
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();
        $allEvents = $em->getRepository('AppBundle:Event')->findAll();

        return $this->render('event/index.html.twig', array(
            'events' => $allEvents
        ));
    }

    /**
     * Show Event Around User
     *
     * @Route("/around", name="event_around")
     * @Method("GET")
     * @param CheckDistance $checkDistance
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function aroundAction(CheckDistance $checkDistance)
    {
        $date = new \DateTime('now');
        $em = $this->getDoctrine()->getManager();
        $events = $em->getRepository('AppBundle:Event')->findEventsWhoHaveEditionByDate($date);

        $user = $this->getUser();
        $userLat = $user->getLatitude();
        $userLng = $user->getLongitude();
        $userMobility = $user->getMobility();
        $proxEvents = [];

        foreach ($events as $event) {
            $eventLat = $event->getLatitude();
            $eventLng= $event->getLongitude();
            $distance = $checkDistance->getDistance($userLat, $userLng, $eventLat, $eventLng);

            if ($distance < $userMobility) {
                array_push($proxEvents, $event);
            }
        }
        return $this->render('event/proximity_events.html.twig', [
            'events' => $proxEvents,
        ]);
    }
  
    /**
     * @param Event $event
     * @Route("/{slug}/follow", name="event_follow")
     * @Method("GET")
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function followAction(Event $event)
    {
        $em = $this->getDoctrine()->getManager();
        $isAFollower = false;
        foreach ($event->getFollowers() as $user) {
            if ($user->getId() === $this->getUser()->getId()) {
                $this->addFlash('warning', 'Vous suivez déjà cet évènement');
                $isAFollower = true;
                break;
            }
        }
        if (!$isAFollower) {
            $event->addFollower($this->getUser());
            $this->getUser()->addEventsFollowed($event);
            $em->flush();
            $this->addFlash('success', 'Vous suivez cet évènement');
        }

        return $this->redirectToRoute('event_show', array('slug' => $event->getSlug()));
    }

    /**
     * Creates a new event entity.
     *
     * @Route("/new", name="event_new")
     * @Method({"GET", "POST"})
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function newAction(Request $request, SlugService $slugService)
    {
        $event = new Event();
        $form = $this->createForm('AppBundle\Form\EventType', $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $event->setCreator($this->getUser());
            $em = $this->getDoctrine()->getManager();
            $event->setSlug($slugService->generateSlug($event->getTitle()));
            $em->persist($event);
            $em->flush();

            return $this->redirectToRoute('event_show', array('slug' => $event->getSlug()));
        }

        return $this->render('event/new.html.twig', array(
            'event' => $event,
            'form' => $form->createView(),
        ));
    }

    /**
     * Finds and displays a event entity.
     *
     * @Route("/{slug}", name="event_show")
     * @Method("GET")
     */
    public function showAction(Event $event)
    {
        $deleteForm = $this->createDeleteForm($event);
        $user = $this->getUser();
        $today = new \DateTime();

        return $this->render('event/show.html.twig', array(
            'event' => $event,
            'user' => $user,
            'today' => $today,
            'delete_form' => $deleteForm->createView(),
        ));
    }

    /**
     * Displays a form to edit an existing event entity.
     *
     * @Route("/{slug}/edit", name="event_edit")
     * @Method({"GET", "POST"})
     */
    public function editAction(Request $request, Event $event, SlugService $slugService, CheckUserRole $checkUserRole)
    {
        $currentUser = $this->getUser();
        $isCreator = $checkUserRole->checkCreator($currentUser, $event);
        if (!$isCreator) {
            return $this->redirectToRoute('event_show', array('slug' => $event->getSlug()));
        }
        $deleteForm = $this->createDeleteForm($event);
        $editForm = $this->createForm('AppBundle\Form\EventType', $event);
        $editForm->handleRequest($request);
        $today = new \DateTime();

        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $event->setSlug($slugService->generateSlug($event->getTitle()));
            $this->getDoctrine()->getManager()->flush();

            return $this->redirectToRoute('event_edit', array('slug' => $event->getSlug()));
        }

        return $this->render('event/edit.html.twig', array(
            'event' => $event,
            'edit_form' => $editForm->createView(),
            'delete_form' => $deleteForm->createView(),
            'today' => $today,
        ));
    }

    /**
     * Deletes a event entity.
     *
     * @Route("/{id}", name="event_delete")
     * @Method("DELETE")
     */
    public function deleteAction(Request $request, Event $event)
    {
        $form = $this->createDeleteForm($event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($event->getEditions()->count() > 0) {
                $this->addFlash('danger', 'Vous ne pouvez pas supprimer un évènement qui contient une edition');
                return $this->redirectToRoute('event_edit', array('slug' => $event->getSlug()));
            }
            $em = $this->getDoctrine()->getManager();
            $em->remove($event);
            $em->flush();
        }

        return $this->redirectToRoute('event_index');
    }

    /**
     * Creates a form to delete a event entity.
     *
     * @param Event $event The event entity
     *
     * @return \Symfony\Component\Form\FormInterface
     */
    private function createDeleteForm(Event $event)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('event_delete', array('id' => $event->getId())))
            ->setMethod('DELETE')
            ->getForm()
        ;
    }
}
