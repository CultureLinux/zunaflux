<?php
declare(strict_types=1);
namespace App\Controller;
use App\Application\FeedExplorer\FeedCatalog;
use App\Application\Subscription\CreateCategory;
use App\Application\Subscription\SubscribeToFeed;
use App\Entity\Category;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
final class FeedExplorerController extends AbstractController
{
 #[Route('/explorer',name:'app_feed_explorer',methods:['GET','POST'])]
 public function index(Request $r,FeedCatalog $catalog,SubscribeToFeed $subscribe,CreateCategory $create,EntityManagerInterface $em):Response
 {
  $this->denyAccessUnlessGranted('ROLE_ADMIN');$user=$this->getUser();if(!$user instanceof User)throw $this->createAccessDeniedException();
  if($r->isMethod('POST')){$this->add($r,$catalog,$subscribe,$create,$em,$user);return $this->redirectToRoute('app_feed_explorer');}
  $language=$r->query->getString('language');if(!in_array($language,['fr','en','de'],true))$language=null;
  $categoryGroups=$catalog->grouped($language);$category=$r->query->getString('category');if(!array_key_exists($category,$categoryGroups))$category=null;
  return $this->render('feed_explorer/index.html.twig',['groups'=>$catalog->grouped($language,$category),'category_groups'=>$categoryGroups,'active_language'=>$language,'active_category'=>$category,'categories'=>$em->getRepository(Category::class)->findBy(['user'=>$user],['name'=>'ASC'])]);
 }
 private function add(Request $r,FeedCatalog $catalog,SubscribeToFeed $subscribe,CreateCategory $create,EntityManagerInterface $em,User $user):void
 {
  if(!$this->isCsrfTokenValid('feed-explorer',$r->request->getString('_token')))throw $this->createAccessDeniedException();$feed=$catalog->find($r->request->getString('feed'));if(null===$feed)throw $this->createNotFoundException();$id=$r->request->getString('category');$category=null;
  if('new'===$id)$category=$create->create($user,$r->request->getString('new_category'));elseif(''!==$id){try{$category=$em->getRepository(Category::class)->find(Uuid::fromString($id));}catch(\InvalidArgumentException){}if(!$category instanceof Category||!$category->getUser()->getId()->equals($user->getId()))throw $this->createAccessDeniedException();}
  $sub=$subscribe->subscribe($user,$feed['url']);$sub->assignCategory($category);$em->flush();$this->addFlash('success','Flux ajouté à vos abonnements.');
 }
}
