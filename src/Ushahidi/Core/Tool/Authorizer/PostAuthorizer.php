<?php

/**
 * Ushahidi Post Authorizer
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi\Application
 * @copyright  2014 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

namespace Ushahidi\Core\Tool\Authorizer;

use Ushahidi\Contracts\Entity;
use Ushahidi\Core\Entity\Post;
use Ushahidi\Core\Entity\User;
use Ushahidi\Contracts\Authorizer;
use Ushahidi\Core\Entity\Permission;
use Ushahidi\Core\Concerns\AccessPrivileges;
use Ushahidi\Core\Concerns\AdminAccess;
use Ushahidi\Core\Concerns\OwnerAccess;
use Ushahidi\Core\Concerns\UserContext;
use Ushahidi\Contracts\ParentableEntity;
use Ushahidi\Core\Concerns\ParentAccess;
use Ushahidi\Core\Entity\FormRepository;
use Ushahidi\Core\Entity\PostRepository;
use Ushahidi\Core\Concerns\ControlAccess;
use Ushahidi\Core\Concerns\PrivateDeployment;

// The `PostAuthorizer` class is responsible for access checks on `Post` Entities
class PostAuthorizer implements Authorizer
{
    // The access checks are run under the context of a specific user
    use UserContext;

    // It uses methods from several traits to check access:
    // - `OwnerAccess` to check if a user owns the post, the
    // - `ParentAccess` to check if the user can access a parent post,
    // - `AdminAccess` to check if the user has admin access
    use AdminAccess, OwnerAccess, ParentAccess;

    // It uses `AccessPrivileges` to provide the `getAllowedPrivs` method.
    use AccessPrivileges;

    // It uses `PrivateDeployment` to check whether a deployment is private
    use PrivateDeployment;

    // Check that the user has the necessary permissions
    // if roles are available for this deployment.
    use ControlAccess;

    /**
     * Get a list of all possible privilges.
     * By default, returns standard HTTP REST methods.
     * @return array
     */
    protected function getAllPrivs()
    {
        return ['read', 'create', 'update', 'delete', 'search', 'change_status', 'read_full'];
    }

    // It requires a `PostRepository` to load parent posts too.
    protected $post_repo;

    // It requires a `FormRepository` to determine create access.
    protected $form_repo;

    public function __construct(PostRepository $post_repo, FormRepository $form_repo)
    {
        $this->post_repo = $post_repo;
        $this->form_repo = $form_repo;
    }

    /* Authorizer */
    public function isAllowed(Entity $post, $privilege)
    {
        // These checks are run within the user context.
        $user = $this->getUser();

        // Only logged in users have access if the deployment is private
        if (!$this->canAccessDeployment($user)) {
            return false;
        }

        // First check whether there is a role with the right permissions
        if (($privilege !== "delete") && ($this->acl->hasPermission($user, Permission::MANAGE_POSTS))) {
            return true;
        }

        if (($privilege === "delete") && ($this->acl->hasPermission($user, Permission::DELETE_POSTS))) {
            return true;
        }

        // Then we check if a user has the 'admin' role. If they do they're
        // allowed access to everything (all entities and all privileges)
        if ($this->isUserAdmin($user)) {
            return true;
        }

        // We check if the user has access to a parent post. This doesn't
        // grant them access, but is used to deny access even if the child post
        // is public.
        if (! $this->isAllowedParent($post, $privilege)) {
            return false;
        }

        // Non-admin users are not allowed to create posts for other users.
        // Post must be created for owner, or if the user is anonymous post must have no owner.
        if ($privilege === 'create'
            && !$this->isUserOwner($post, $user)
            && !$this->isUserAndOwnerAnonymous($post, $user)
            ) {
            return false;
        }

        // Non-admin users are not allowed to create posts for forms that have restricted access.
        if (in_array($privilege, ['create', 'update', 'lock'])
            && $this->isFormRestricted($post, $user)
            ) {
            return false;
        }

        // All users are allowed to create and search posts.
        if (in_array($privilege, ['create', 'search'])) {
            return true;
        }

        // If a post is published, then anyone with the appropriate role can read it
        if ($privilege === 'read' && $this->isPostPublishedToUser($post, $user)) {
            return true;
        }

        // If entity isn't loaded (ie. pre-flight check) then *anyone* can view it.
        if ($privilege === 'read' && ! $post->getId()) {
            return true;
        }

        // TODO: This logic isn't sufficient, as it's not explicit about what happens
        // Only admins or users with 'Manage Posts' permission can change status
        if ($privilege === 'change_status') {
            return false;
        }

        // Only admins or users with 'Manage Posts' permission can change the ownership of a post
        if ($post->hasChanged('user_id')) {
            return false;
        }

        // If the user is the owner of this post & they have edit own posts permission
        // they are allowed to edit the post. They can't change the post status or
        // ownership but those are already checked above
        if ($this->isUserOwner($post, $user)
            && in_array($privilege, ['update', 'lock'])
            && $this->acl->hasPermission($user, Permission::EDIT_OWN_POSTS)) {
            return true;
        }

        // If the user is the owner of this post & they have delete own posts permission
        // they are allowed to edit or delete the post.
        if ($this->isUserOwner($post, $user)
            &&($privilege === "delete")
            && $this->acl->hasPermission($user, Permission::DELETE_OWN_POSTS)) {
            return true;
        }

        // If the user is the owner of this post they can always view the post
        if ($this->isUserOwner($post, $user)
            && in_array($privilege, ['read'])) {
            return true;
        }

        // If no other access checks succeed, we default to denying access
        return false;
    }

    protected function isPostPublishedToUser(Post $post, User $user)
    {
        if ($post->status === 'published' && $this->isUserOfRole($post, $user)) {
            return true;
        }
        return false;
    }

    protected function isUserOfRole(Post $post, $user)
    {
        if ($post->published_to) {
            return in_array($user->role, $post->published_to);
        }

        // If no visibility info, assume public
        return true;
    }

    /* ParentAccess */
    protected function getParent(ParentableEntity $entity)
    {
        // If the post has a parent_id, we attempt to load it from the `PostRepository`
        if ($entity->parent_id) {
            return $this->post_repo->get($entity->parent_id);
        }

        return false;
    }

    /* FormRole */
    protected function isFormRestricted(Post $post, $user)
    {
        // If the $entity->form_id exists and the $form->everyone_can_create is False
        // we check to see if the Form & Role Join exists in the `FormRoleRepository`

        if ($post->form_id) {
            $roles = $this->form_repo->getRolesThatCanCreatePosts($post->form_id);

            if ($roles['everyone_can_create'] > 0) {
                return false;
            }

            if (is_array($roles['roles']) && in_array($user->role, $roles['roles'])) {
                return false;
            }
        }

        return true;
    }
}
