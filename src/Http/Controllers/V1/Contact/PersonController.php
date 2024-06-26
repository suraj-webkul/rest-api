<?php

namespace Webkul\RestApi\Http\Controllers\V1\Contact;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Webkul\Attribute\Http\Requests\AttributeForm;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\RestApi\Http\Controllers\V1\Controller;
use Webkul\RestApi\Http\Request\MassDestroyRequest;
use Webkul\RestApi\Http\Resources\V1\Contact\PersonResource;

class PersonController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(protected PersonRepository $personRepository)
    {
        $this->addEntityTypeInRequest('persons');
    }

    /**
     * Display a listing of the persons.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $persons = $this->allResources($this->personRepository);

        return PersonResource::collection($persons);
    }

    /**
     * Show resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show(int $id)
    {
        $resource = $this->personRepository->find($id);

        return new PersonResource($resource);
    }

    /**
     * Search person results.
     *
     * @return \Illuminate\Http\Response
     */
    public function search()
    {
        $persons = $this->personRepository->findWhere([
            ['name', 'like', '%'.urldecode(request()->input('query')).'%'],
        ]);

        return PersonResource::collection($persons);
    }

    /**
     * Create the person.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(AttributeForm $request)
    {
        Event::dispatch('contacts.person.create.before');

        $person = $this->personRepository->create($this->sanitizeRequestedPersonData());

        Event::dispatch('contacts.person.create.after', $person);

        return new JsonResponse([
            'data'    => new PersonResource($person),
            'message' => trans('admin::app.contacts.persons.create-success'),
        ]);
    }

    /**
     * Update the person.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(AttributeForm $request, $id)
    {
        Event::dispatch('contacts.person.update.before', $id);

        $person = $this->personRepository->update($this->sanitizeRequestedPersonData(), $id);

        Event::dispatch('contacts.person.update.after', $person);

        return new JsonResponse([
            'data'    => new PersonResource($person),
            'message' => trans('admin::app.contacts.persons.update-success'),
        ]);
    }

    /**
     * Remove the person.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            Event::dispatch('contacts.person.delete.before', $id);

            $this->personRepository->delete($id);

            Event::dispatch('contacts.person.delete.after', $id);

            return new JsonResponse([
                'message' => trans('admin::app.response.destroy-success', ['name' => trans('admin::app.contacts.persons.person')]),
            ]);
        } catch (\Exception $exception) {
            return new JsonResponse([
                'message' => trans('admin::app.response.destroy-failed', ['name' => trans('admin::app.contacts.persons.person')]),
            ], 500);
        }
    }

    /**
     * Mass delete the persons.
     *
     * @return \Illuminate\Http\Response
     */
    public function massDestroy(MassDestroyRequest $massDestroyRequest)
    {
        $personIds = $massDestroyRequest->input('indices', []);

        foreach ($personIds as $personId) {
            $person = $this->personRepository->find($personId);

            if (! $person) {
                continue;
            }

            Event::dispatch('contact.person.delete.before', $personId);

            $person->delete($personId);

            Event::dispatch('contact.person.delete.after', $personId);
        }

        return new JsonResponse([
            'message' => trans('admin::app.response.destroy-success', ['name' => trans('admin::app.contacts.persons.title')]),
        ]);
    }

    /**
     * Sanitize requested person data and return the clean array.
     */
    private function sanitizeRequestedPersonData(): array
    {
        $data = request()->all();

        $data['contact_numbers'] = collect($data['contact_numbers'])->filter(fn ($number) => ! is_null($number['value']))->toArray();

        return $data;
    }
}
