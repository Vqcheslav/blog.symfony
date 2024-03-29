<?php

namespace App\Controller;

use App\Entity\Post;
use App\Entity\Comment;
use App\Service\PostService;
use App\Service\CommentService;
use App\Service\RedisCacheService;
use App\Form\PostFormType;
use App\Form\CommentFormType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/post', name: 'post_')]
class PostController extends AbstractController
{
    public const MAX_SIZE_OF_IMAGE = 4194304; // 4 megabytes (4*1024*1024 bytes)

    private PostService $postService;

    private CommentService $commentService;

    private RedisCacheService $cacheService;

    public function __construct(
        PostService       $postService,
        CommentService    $commentService,
        RedisCacheService $cacheService
    ) {
        $this->postService    = $postService;
        $this->commentService = $commentService;
        $this->cacheService   = $cacheService;
    }

    #[Route('', name: 'main')]
    public function main(): Response
    {
        $numberOfPosts           = 10;
        $numberOfMoreTalkedPosts = 3;
        $posts                   = $this
            ->cacheService
            ->get(
                'last_posts', 
                10, 
                sprintf('%s[]', Post::class),
                function () use ($numberOfPosts) {
                    return $this->postService->getLastPosts($numberOfPosts);
                }
            ); 
        $moreTalkedPosts = $this
            ->cacheService
            ->get(
                'more_talked_posts', 
                10, 
                sprintf('%s[]', Post::class),
                function () use ($numberOfMoreTalkedPosts) {
                    return $this->postService->getMoreTalkedPosts($numberOfMoreTalkedPosts);
                }
            );
        $response = $this->render('post/post_home.html.twig', [
            'posts'           => $posts,
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
        $posts = $this
            ->cacheService
            ->get(
                sprintf('all_posts_%s_%s', $numberOfPosts, $page), 
                10, 
                sprintf('%s[]', Post::class),
                function () use ($numberOfPosts, $page) {
                    return $this->postService->getPosts($numberOfPosts, $page);
                }
            );

        return $this->render('post/posts.html.twig', [
            'nameOfPath' => 'post_show_all',
            'number'     => $numberOfPosts,
            'page'       => $page,
            'posts'      => $posts
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '(?!0)\b[0-9]+'])]
    public function showPost(int $id): Response
    {
        $post = $this
            ->cacheService
            ->get(
                sprintf('post_%s', $id), 
                10,
                Post::class,
                function () use ($id) {
                    return $this->postService->getPostById($id);
                }
            );

        if (empty($post)) {
            throw $this->createNotFoundException(sprintf('Пост с id = %s не найден. Вероятно, он удален', $id));
        }

        $regex    = '/#(\w+)/um';
        $content  = preg_replace($regex, "<a class='link' href='/search/%23$1'>$0</a>", $post->getContent());
        $comments = $this
            ->cacheService
            ->get(
                sprintf('comments_post_%s', $id), 
                10, 
                sprintf('%s[]', Comment::class),
                function () use ($id) {
                    return $this->commentService->getCommentsByPostId($id);
                }
            );
        $form = $this->createForm(CommentFormType::class);

        return $this->renderForm('post/post.html.twig', [
            'post'     => $post,
            'content'  => $content,
            'comments' => $comments,
            'form'     => $form
        ]);
    }

    #[Route('/rating/{id}', name: 'rating', methods: ['POST'], requirements: ['id' => '(?!0)\b[0-9]+'])]
    public function addRating(Post $post, Request $request)
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        if (! $post->getApprove()) {
            throw $this->createNotFoundException('Невозможно добавить рейтинг неодобренному посту');
        }
        
        $user   = $this->getUser();
        $rating = (int) $request
            ->request
            ->get('rating');

        if ($this->postService->changeRating($user, $post, $rating)) {
            $this->addFlash(
                'success',
                'Ваша оценка принята'
            );
        } else {
            $this->addFlash(
                'success',
                'Предыдущая оценка удалена. Нажмите ещё раз, чтобы поставить новую'
            );
        }

        return $this->redirectToRoute('post_show', ['id' => $post->getId()]);
    }

    #[Route('/add', name: 'add')]
    public function add(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($user->getIsBanned() > time()) {
            $text = sprintf('Вы забанены. <br> Доступ будет восстановлен %s', date('d.m.Y в H:i', $user->getIsBanned()));

            return $this->render('blog_message.html.twig', [
                'description' => $text
            ]);
        }

        $form = $this->createForm(PostFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $title = $form
                ->get('title')
                ->getData();
            $content = $form
                ->get('content')
                ->getData();
            $approve = false;

            if ($this->isGranted('ROLE_MODERATOR')) {
                $approve = true;
            }

            $post = $this
                ->postService
                ->create($user, $title, $content, $approve);

            if (! empty($post)) {
                if ($approve) {
                    $this->addFlash(
                        'success',
                        'Пост добавлен'
                    );
                } else {
                    $this->addFlash(
                        'success',
                        'Пост отправлен на модерацию'
                    );
                }
            } else {
                $this->addFlash(
                    'error',
                    'При добавлении поста произошла ошибка'
                );
            }

            return $this->redirectToRoute('post_main');
        }

        return $this->renderForm('post/post_add.html.twig', [
            'form'              => $form,
            'max_size_of_image' => $this::MAX_SIZE_OF_IMAGE
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '(?!0)\b[0-9]+'])]
    public function edit(Post $post, Request $request): Response
    {
        $this->denyAccessUnlessGranted('edit', $post);
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $form = $this->createForm(PostFormType::class, $post, [
            'title' => $post->getTitle(),
            'content'   => $post->getContent()
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $post = $form->getData();
            $post->setApprove(false);

            if ($this->isGranted('ROLE_MODERATOR')) {
                $post->setApprove(true);
            }

            $this->postService->update($post);

            if ($this->isGranted('ROLE_ADMIN') || $user->getId() === $post->getUser()->getId()) {
                return $this->redirectToRoute('post_main');
            } else {
                return $this->redirectToRoute('moderator_posts');
            }
        }

        return $this->renderForm('post/post_add.html.twig', [
            'form'              => $form,
            'max_size_of_image' => $this::MAX_SIZE_OF_IMAGE
        ]);
    }

    #[Route('/delete/{id}', name: 'delete', requirements: ['id' => '(?!0)\b[0-9]+'])]
    public function deletePost(Post $post): Response
    {
        $this->denyAccessUnlessGranted('delete', $post);
        $postId = $post->getId();

        $this
            ->postService
            ->delete($post);
        $this->addFlash(
            'success',
            sprintf('Пост №%s удален', $postId)
        );

        if (!$post->getApprove()) {
            return $this->redirectToRoute('moderator_posts');
        }

        return $this->redirectToRoute('post_main');
    }
}