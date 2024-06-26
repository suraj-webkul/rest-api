<?php

namespace Webkul\RestApi\Http\Controllers\V1\Lead;

use Illuminate\Support\Facades\Event;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\RestApi\Http\Controllers\V1\Controller;
use Webkul\RestApi\Http\Resources\V1\Lead\LeadResource;

class TagController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(protected LeadRepository $leadRepository)
    {
    }

    /**
     * Store a newly created tag in storage.
     *
     * @param  int  $leadId
     * @return \Illuminate\Http\Response
     */
    public function store($leadId)
    {
        Event::dispatch('leads.tag.create.before', $leadId);

        $lead = $this->leadRepository->find($leadId);

        if (! $lead->tags->contains(request('id'))) {
            $lead->tags()->attach(request('id'));
        }

        Event::dispatch('leads.tag.create.after', $lead);

        return response([
            'data'    => new LeadResource($lead),
            'message' => trans('admin::app.leads.tag-create-success'),
        ]);
    }

    /**
     * Remove the specified tag from storage.
     *
     * @param  int  $leadId
     * @return \Illuminate\Http\Response
     */
    public function delete($leadId)
    {
        Event::dispatch('leads.tag.delete.before', $leadId);

        $lead = $this->leadRepository->find($leadId);

        $lead->tags()->detach(request('id'));

        Event::dispatch('leads.tag.delete.after', $lead);

        return response([
            'data'    => new LeadResource($lead),
            'message' => trans('admin::app.leads.tag-destroy-success'),
        ]);
    }
}
