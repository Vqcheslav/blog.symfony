<?php

namespace App\Controller;

use App\Entity\Post;
use App\Service\PostService;
use App\Service\RedisService;
use App\Form\PostFormType;
use App\Form\CommentFormType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/post', name: 'post_')]
class PostController extends AbstractController
{
    private int $maxSizeOfUploadImage = 4194304; // 4 megabytes (4*1024*1024 bytes)
    private PostService $postService;
    private CacheInterface $pool;

    public function __construct(
        PostService $postService,
        CacheInterface $pool,
        RedisService $redisService
    ) {
        $this->postService = $postService;
        $this->redisService = $redisService;
        $this->pool = $pool;
    }

    #[Route('', name: 'main')]
    public function main(): Response
    {
        $numberOfPosts = 10;
        $numberOfMoreTalkedPosts = 3;

        $posts = $this->redisService->getLastPosts($numberOfPosts, 10);

        $moreTalkedPosts = $this->pool->get('more_talked_posts', 
            function (ItemInterface $item) use ($numberOfMoreTalkedPosts) {
                $item->expiresAfter(60);
                $computedValue = $this->postService->getMoreTalkedPosts($numberOfMoreTalkedPosts);

                return $computedValue;
        });

        $response = $this->render('post/home.html.twig', [
            'posts' => $posts,
            'moreTalkedPosts' => $moreTalkedPosts
        ]);

        // $response->setEtag(md5($response->getContent()));
        // $response->setPublic();
        // $response->isNotModified($request);
        // $response->headers->addCacheControlDirective('must-revalidate', true);

        return $response;
    }

    #[Route('/all/{numberOfPosts<(?!0)\b[0-9]+>?25}/{page<(?!0)\b[0-9]+>?1}', name: 'show_all')]
    public function showAll(int $numberOfPosts, int $page): Response
    {
        $posts = $this->pool->get(sprintf('all_posts_%s_%s', $numberOfPosts, $page),
            function (ItemInterface $item) use ($numberOfPosts, $page) {
                $item->expiresAfter(60);
                $computedValue = $this->postService->getPosts($numberOfPosts, $page);

                return $computedValue;
        });

        return $this->render('post/allposts.html.twig', [
            'nameOfPath' => 'post_show_all',
            'number' => $numberOfPosts,
            'page' => $page,
            'posts' => $posts
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '(?!0)\b[0-9]+'])]
    public function showPost(int $id): Response
    {
        $post = $this->pool->get(sprintf('post_%s', $id), function (ItemInterface $item) use ($id) {
            $item->expiresAfter(60);
            $computedValue = $this->postService->getPostById($id);

            return $computedValue;
        });

        if (!$post) {
            throw $this->createNotFoundException(sprintf('Пост с id = %s не найден. Вероятно, он удален', $id));
        }

        $form = $this->createForm(CommentFormType::class);

        return $this->renderForm('post/view.html.twig', [
            'post' => $post,
            'form' => $form
        ]);
    }

    #[Route('/add', name: 'add')]
    public function add(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');
        $form = $this->createForm(PostFormType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            $title = $form->get('title')->getData();
            $content = $form->get('content')->getData();
            if ($this->postService->create($user, $title, $content)) {
                $this->addFlash(
                    'success',
                    'Пост добавлен'
                );
            } else {
                $this->addFlash(
                    'error',
                    'При добавлении поста произошла ошибка'
                );
            }
            return $this->redirectToRoute('post_main');
        }
        return $this->renderForm('post/add.html.twig', [
            'form' => $form,
            'max_size_of_upload_image' => $this->maxSizeOfUploadImage
        ]);
    }

    #[Route('/rating/{id}', name: 'rating', methods: ['POST'], requirements: ['id' => '(?!0)\b[0-9]+'])]
    public function addRating(Post $post, Request $request)
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');
        $user = $this->getUser();
        $rating = (int) $request->request->get('rating');
        if ($this->postService->addRating($user, $post, $rating)) {
            $this->addFlash(
                'success',
                'Ваша оценка принята'
            );
        } else {
            $this->addFlash(
                'error',
                'Вы уже оставили оценку для этого поста'
            );
        }

        return $this->redirectToRoute('post_show', ['id' => $post->getId()]);
    }

    #[Route('/delete/{id}', name: 'delete', requirements: ['id' => '(?!0)\b[0-9]+'])]
    public function deletePost(Post $post): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->postService->delete($post);
        $this->addFlash(
            'success',
            'Пост удален'
        );
        return $this->redirectToRoute('post_main');
    }
}