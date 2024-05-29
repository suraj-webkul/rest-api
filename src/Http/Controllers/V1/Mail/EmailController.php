<?php

namespace Webkul\RestApi\Http\Controllers\V1\Mail;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Webkul\Email\Mails\Email;
use Webkul\Email\Repositories\AttachmentRepository;
use Webkul\Email\Repositories\EmailRepository;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\RestApi\Http\Controllers\V1\Controller;
use Webkul\RestApi\Http\Resources\V1\Email\EmailResource;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\RestApi\Http\Request\MassDestroyRequest;
use Webkul\RestApi\Http\Request\MassUpdateRequest;

class EmailController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected LeadRepository $leadRepository,
        protected EmailRepository $emailRepository,
        protected AttachmentRepository $attachmentRepository,
        protected AttributeRepository $attributeRepository
    ) {
    }

    /**
     * Display a listing of the emails.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $emails = $this->allResources($this->emailRepository);

        return EmailResource::collection($emails);
    }

    /**
     * Show resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show(int $id)
    {
        $resource = $this->emailRepository->find($id);

        return new EmailResource($resource);
    }

    /**
     * Store a newly created email in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store()
    {
        $this->validate(request(), [
            'reply_to' => 'required|array|min:1',
            'reply'    => 'required',
        ]);

        Event::dispatch('email.create.before');

        $uniqueId = time().'@'.config('mail.domain');

        $referenceIds = [];

        if ($parentId = request()->input('parent_id')) {
            $parent = $this->emailRepository->findOrFail($parentId);

            $referenceIds = $parent->reference_ids ?? [];
        }

        $email = $this->emailRepository->create(array_merge(request()->all(), [
            'source'        => 'web',
            'from'          => 'admin@example.com',
            'user_type'     => 'admin',
            'folders'       => ($isDraft = request()->input('is_draft')) ? ['draft'] : ['outbox'],
            'name'          => auth()->guard()->user()->name,
            'unique_id'     => $uniqueId,
            'message_id'    => $uniqueId,
            'reference_ids' => array_merge($referenceIds, [$uniqueId]),
            'user_id'       => auth()->guard()->user()->id,
        ]));

        if (! $isDraft) {
            try {
                Mail::send(new Email($email));

                $this->emailRepository->update([
                    'folders' => ['inbox', 'sent'],
                ], $email->id);
            } catch (\Exception $e) {
            }
        }

        Event::dispatch('email.create.after', $email);

        if ($isDraft) {
            return new JsonResource([
                'data'    => new EmailResource($email),
                'message' => trans('admin::app.mail.saved-to-draft'),
            ]);
        }

        return new JsonResource([
            'data'    => new EmailResource($email),
            'message' => trans('admin::app.mail.create-success'),
        ]);
    }

    /**
     * Update the specified email in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        Event::dispatch('email.update.before', $id);

        $data = request()->all();

        if (! is_null($isDraft = $data['is_draft'])) {
            $data['folders'] = $isDraft ? ['draft'] : ['outbox'];
        }

        $email = $this->emailRepository->update($data, $data['id'] ?? $id);

        Event::dispatch('email.update.after', $email);

        if (
            ! is_null($isDraft)
            && ! $isDraft
        ) {
            try {
                Mail::send(new Email($email));

                $this->emailRepository->update([
                    'folders' => ['inbox', 'sent'],
                ], $email->id);
            } catch (\Exception $e) {
            }
        }

        if (! is_null($isDraft)) {
            if ($isDraft) {
                return response([
                    'data'    => new EmailResource($email),
                    'message' => trans('admin::app.mail.saved-to-draft'),
                ]);
            } else {
                return response([
                    'data'    => new EmailResource($email),
                    'message' => trans('admin::app.mail.create-success'),
                ]);
            }
        }

        $response = [
            'data'    => new EmailResource($email),
            'message' => trans('admin::app.mail.update-success'),
        ];

        if (request('lead_id')) {
            $response['html'] = view('admin::common.custom-attributes.view', [
                'customAttributes' => app('Webkul\Attribute\Repositories\AttributeRepository')->findWhere([
                    'entity_type' => 'leads',
                ]),
                'entity'           => $this->leadRepository->find(request('lead_id')),
            ])->render();
        }

        return new JsonResource($response);
    }

    /**
     * Remove the specified email from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            $type = request()->input('type', 'delete');

            Event::dispatch("email.$type.before", $id);

            if ($type == 'trash') {
                $this->emailRepository->update([
                    'folders' => ['trash'],
                ], $id);
            } else {
                $this->emailRepository->delete($id);
            }

            Event::dispatch("email.$type.after", $id);

            return new JsonResource([
                'message' => trans('admin::app.mail.delete-success'),
            ]);
        } catch (\Exception $exception) {
            return new JsonResource([
                'message' => trans('admin::app.mail.delete-failed'),
            ], 500);
        }
    }

    /**
     * Mass update the specified emails.
     *
     * @return \Illuminate\Http\Response
     */
    public function massUpdate(MassUpdateRequest $massUpdateRequest)
    {
        $emailIds = $massUpdateRequest->input('indices', []);

        foreach ($emailIds as $emailId) {
            $email = $this->emailRepository->find($emailId);

            if (! $email) {
                continue;
            }

            Event::dispatch('email.update.before', $emailId);

            $email->update([
                'folders' => request()->input('folders'),
            ]);

            Event::dispatch('email.update.after', $emailId);
        }

        return new JsonResource([
            'message' => trans('admin::app.mail.mass-update-success'),
        ]);
    }

    /**
     * Mass delete the specified emails.
     *
     * @return \Illuminate\Http\Response
     */
    public function massDestroy(MassDestroyRequest $massDestroyRequest)
    {
        $emails = $massDestroyRequest->input('indices', []);

        $type = request()->input('type', 'delete');

        foreach ($emails as $emailId) {
            $email = $this->emailRepository->find($emailId);

            if (! $email) {
                continue;
            }

            Event::dispatch("email.$type.before", $emailId);

            if ($type == 'trash') {
                $email->update([
                    'folders' => ['trash'],
                ]);
            } else {
                $email->delete();
            }

            Event::dispatch("email.$type.after", $emailId);
        }

        return response([
            'message' => trans('admin::app.mail.destroy-success'),
        ]);
    }

    /**
     * Download file from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function download($id)
    {
        $attachment = $this->attachmentRepository->findOrFail($id);

        return Storage::download($attachment->path);
    }
}
