<?php

namespace Drupal\password_policy;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\user\UserInterface;

class PasswordPolicyValidator implements PasswordPolicyValidatorInterface {

  /**
   * The password constraint plugin manager.
   *
   * @var \Drupal\password_policy\PasswordConstraintPluginManager
   */
  protected $passwordConstraintPluginManager;

  /**
   * The password policy storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $passwordPolicyStorage;

  /**
   * PasswordPolicyValidator constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The password policy storage.
   * @param \Drupal\password_policy\PasswordConstraintPluginManager $passwordConstraintPluginManager
   *   The password constraint plugin manager.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, PasswordConstraintPluginManager $passwordConstraintPluginManager) {
    $this->passwordConstraintPluginManager = $passwordConstraintPluginManager;
    $this->passwordPolicyStorage = $entityTypeManager->getStorage('password_policy');
  }

  /**
   * {@inheritdoc}
   */
  public function validatePassword(string $password, UserInterface $user, array $edited_user_roles = []): bool {
    // Stop before policy-based validation if password exceeds maximum length.
    if (strlen($password) > PasswordInterface::PASSWORD_MAX_LENGTH) {
      return TRUE;
    }

    if (empty($edited_user_roles)) {
      $edited_user_roles = $user->getRoles();
      $edited_user_roles = array_combine($edited_user_roles, $edited_user_roles);
    }

    $valid = TRUE;

    // Run validation.
    $applicable_policies = $this->getApplicablePolicies($edited_user_roles);

    $original_roles = $user->getRoles();
    $original_roles = array_combine($original_roles, $original_roles);

    $force_failure = FALSE;
    if ($edited_user_roles !== $original_roles && $password === '' && !empty($applicable_policies)) {
      // New role has been added and applicable policies are available.
      $force_failure = TRUE;
    }

    foreach ($applicable_policies as $policy) {
      $policy_constraints = $policy->getConstraints();

      foreach ($policy_constraints as $constraint) {
        /** @var \Drupal\password_policy\PasswordConstraintInterface $plugin_object */
        $plugin_object = $this->passwordConstraintPluginManager->createInstance($constraint['id'], $constraint);

        // Execute validation.
        $validation = $plugin_object->validate($password, $user);

        if ($valid && $password !== '' && !$validation->isValid()) {
          // Throw error to ensure form will not submit.
          $valid = FALSE;
        }
        elseif ($force_failure) {
          $valid = FALSE;
        }
      }
    }

    return $valid;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPasswordPolicyConstraintsTableRows(string $password, UserInterface $user, array $edited_user_roles = []): array {
    if (empty($edited_user_roles)) {
      $edited_user_roles = $user->getRoles();
      $edited_user_roles = array_combine($edited_user_roles, $edited_user_roles);
    }

    // Run validation.
    $applicable_policies = $this->getApplicablePolicies($edited_user_roles);

    $original_roles = $user->getRoles();
    $original_roles = array_combine($original_roles, $original_roles);

    $force_failure = FALSE;
    if ($edited_user_roles !== $original_roles && $password === '' && !empty($applicable_policies)) {
      // New role has been added and applicable policies are available.
      $force_failure = TRUE;
    }

    $policies_table_rows = [];
    /** @var \Drupal\password_policy\Entity\PasswordPolicy $policy */
    foreach ($applicable_policies as $policy) {
      $policy_constraints = $policy->getConstraints();

      foreach ($policy_constraints as $constraint) {
        /** @var \Drupal\password_policy\PasswordConstraintInterface $plugin_object */
        $plugin_object = $this->passwordConstraintPluginManager->createInstance($constraint['id'], $constraint);

        // Execute validation.
        $validation = $plugin_object->validate($password, $user);
        if (!$force_failure && $validation->isValid()) {
          $status = t('Pass');
        }
        else {
          $message = $validation->getErrorMessage();
          if (empty($message)) {
            $message = t('New role was added or existing password policy changed. Please update your password.');
          }
          $status = t('Fail - @message', ['@message' => $message]);
        }
        $status_class = 'password-policy-constraint-' . ($validation->isValid() ? 'passed' : 'failed');
        $table_row = [
          'data' => [
            'policy' => $policy->label(),
            'status' => $status,
            'constraint' => $plugin_object->getSummary(),
          ],
          'class' => [$status_class],
        ];
        $policies_table_rows[] = $table_row;
      }
    }

    return $policies_table_rows;
  }

  /**
   * Gets policies applicable to the given roles.
   *
   * @param $roles
   *   Roles.
   *
   * @return array
   *   Applicable policies.
   */
  protected function getApplicablePolicies($roles): array {
    $applicable_policies = [];

    foreach ($roles as $role) {
      if ($role) {
        $role_map = ['roles.' . $role => $role];
        $role_policies = $this->passwordPolicyStorage->loadByProperties($role_map);
        /** @var \Drupal\password_policy\Entity\PasswordPolicy $policy */
        foreach ($role_policies as $policy) {
          if (!array_key_exists($policy->id(), $applicable_policies)) {
            $applicable_policies[$policy->id()] = $policy;
          }
        }
      }
    }

    return $applicable_policies;
  }

}
