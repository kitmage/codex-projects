<?php

namespace FluentForm\App\Http\Controllers;

use Exception;
use FluentForm\App\Models\Submission;
use FluentForm\App\Services\Submission\SubmissionService;
use FluentForm\Framework\Support\Arr;

class SubmissionController extends Controller
{
    public function index(SubmissionService $submissionService)
    {
        try {
            $attributes = $this->request->all();
            
            $sanitizeMap = [
                'search'       => 'sanitize_text_field',
                'status'       => 'sanitize_text_field',
                'entry_type'   => 'sanitize_text_field',
                'form_id'      => 'intval',
                'per_page'     => 'intval',
                'page'         => 'intval',
                'is_favourite' => 'rest_sanitize_boolean',
            ];
            
            $attributes = fluentform_backend_sanitizer($attributes, $sanitizeMap);
            
            
            // If frontend sends `entry_type` (used by some components), map it to `status`
            if (isset($attributes['entry_type']) && !isset($attributes['status'])) {
                $attributes['status'] = $attributes['entry_type'];
            }

            if (isset($attributes['date_range']) && is_array($attributes['date_range'])) {
                $attributes['date_range'] = array_map('sanitize_text_field', $attributes['date_range']);
            }
            if (isset($attributes['payment_statuses']) && is_array($attributes['payment_statuses'])) {
                $attributes['payment_statuses'] = array_map('sanitize_text_field', $attributes['payment_statuses']);
            }

            return $this->sendSuccess(
                $submissionService->get($attributes)
            );
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function find(SubmissionService $submissionService, $submissionId)
    {
        try {
            return $this->sendSuccess(
                $submissionService->find($submissionId)
            );
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function resources(SubmissionService $submissionService)
    {
        try {
            $attributes = $this->request->all();
            
            $sanitizeMap = [
                'form_id' => 'intval',
            ];
            $attributes = fluentform_backend_sanitizer($attributes, $sanitizeMap);
            
            return $this->sendSuccess(
                $submissionService->resources($attributes)
            );
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function updateStatus(SubmissionService $submissionService)
    {
        try {
            $status = $submissionService->updateStatus($this->request->all());

            /* translators: %s is the submission status */
            $message = sprintf(__('The submission has been marked as %s', 'fluentform'), $status);

            return $this->sendSuccess([
                'message' => $message,
                'status'  => $status,
            ]);
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function toggleIsFavorite(SubmissionService $submissionService)
    {
        try {
            [$message, $isFavourite] = $submissionService->toggleIsFavorite(
                $this->request->get('entry_id')
            );

            return $this->sendSuccess([
                'message'      => $message,
                'is_favourite' => $isFavourite,
            ]);
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function handleBulkActions(SubmissionService $submissionService)
    {
        try {
            $message = $submissionService->handleBulkActions($this->request->all());

            return $this->sendSuccess(['message' => $message]);
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    public function remove(Submission $submission, $submissionId)
    {
        try {
            $submission::remove([$submissionId]);
	        do_action( 'fluentform/submission_deleted', $submissionId );;

            return $this->sendSuccess([
                'message' => __('Selected submission successfully deleted Permanently', 'fluentform'),
            ]);
    
        } catch (Exception $e){
            return $this->sendError([
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Get user list for submission page
     * @return \WP_REST_Response
     */
    public function submissionUsers()
    {
        $search = sanitize_text_field($this->request->get('search'));
        $users = get_users([
            'search' => "*{$search}*",
            'number' => 50,
        ]);
    
        $formattedUsers = [];
        foreach ($users as $user) {
            $formattedUsers[] = [
                'ID'    => $user->ID,
                'label' => $user->display_name . ' - ' . $user->user_email,
            ];
        }
    
        return $this->sendSuccess([
            'users' => $formattedUsers,
        ]);
    }

    /**
     * Update User of a submission
     * @param SubmissionService $submissionService
     * @return \WP_REST_Response
     */
    public function updateSubmissionUser(SubmissionService $submissionService)
    {
        try {
            $userId = intval($this->request->get('user_id'));
            $submissionId = intval($this->request->get('submission_id'));
            $response = $submissionService->updateSubmissionUser($userId, $submissionId);
            return $this->sendSuccess($response);
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Get All Submissions
     * @param Submission $submission
     * @return \WP_REST_Response
     */
    public function all(Submission $submission)
    {
        try {
            $attributes = $this->request->all();

            // Use backend sanitizer map for scalar fields (preserves expected types)
            $sanitizeMap = [
                'search'       => 'sanitize_text_field',
                'status'       => 'sanitize_text_field',
                'entry_type'   => 'sanitize_text_field',
                'form_id'      => 'intval',
                'per_page'     => 'intval',
                'page'         => 'intval',
                'is_favourite' => 'rest_sanitize_boolean',
            ];

            $attributes = fluentform_backend_sanitizer($attributes, $sanitizeMap);

            // Handle frontend `entry_type` param (sanitize and map to `status` if needed)
            if (isset($attributes['entry_type']) && !isset($attributes['status'])) {
                $attributes['status'] = $attributes['entry_type'];
            }

            // Sanitize array fields explicitly (sanitizer recurses but won't apply parent's key sanitizer to numeric child keys)
            if (isset($attributes['date_range']) && is_array($attributes['date_range'])) {
                $attributes['date_range'] = array_map('sanitize_text_field', $attributes['date_range']);
            }
            if (isset($attributes['payment_statuses']) && is_array($attributes['payment_statuses'])) {
                $attributes['payment_statuses'] = array_map('sanitize_text_field', $attributes['payment_statuses']);
            }

            return $this->sendSuccess(
                $submission->allSubmissions($attributes)
            );
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }
    /**
     * Get printable content
     * @param SubmissionService $submissionService
     * @return \WP_REST_Response
     */
    public function print(SubmissionService $submissionService)
    {
        try {
            $attributes = $this->request->all();
            
            $sanitizeMap = [
                'entry_ids' => function($value) {
                    if (is_array($value)) {
                        return array_map('intval', $value);
                    }
                    return [];
                },
                'form_id' => 'intval',
            ];
            $attributes = fluentform_backend_sanitizer($attributes, $sanitizeMap);
            
            return $this->sendSuccess(
                $submissionService->getPrintContent($attributes)
            );
        } catch (Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }
}
