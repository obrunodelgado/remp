<?php

namespace App\Http\Controllers;

use App\Banner;
use App\Campaign;
use App\CampaignSegment;
use App\Contracts\SegmentAggregator;
use App\Contracts\SegmentException;
use App\Http\Requests\CampaignRequest;
use App\Jobs\CacheSegmentJob;
use Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use View;
use Yajra\Datatables\Datatables;
use App\Models\Dimension\Map as DimensionMap;
use App\Models\Position\Map as PositionMap;
use App\Models\Alignment\Map as AlignmentMap;

class CampaignController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('campaigns.index', [
            'snippet' => view('campaigns._snippet'),
        ]);
    }

    public function json(Datatables $dataTables)
    {
        $campaigns = Campaign::query();
        return $dataTables->of($campaigns->with('segments'))
            ->addColumn('actions', function (Campaign $campaign) {
                return[
                    'edit' => route('campaigns.edit', $campaign) ,
                ];
            })
            ->rawColumns(['actions', 'active'])
            ->setRowId('id')
            ->make(true);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @param SegmentAggregator $segmentAggregator
     * @return \Illuminate\Http\Response
     */
    public function create(SegmentAggregator $segmentAggregator)
    {
        $campaign = new Campaign();
        $campaign->fill(old());
        $selectedSegments = collect(old('segments'));

        $banners = Banner::all();
        try {
            $segments = $segmentAggregator->list();
        } catch (SegmentException $e) {
            $segments = new Collection();
            flash('Unable to fetch list of segments, please check the application configuration.')->error();
            \Log::error($e->getMessage());
        }

        return view('campaigns.create', [
            'campaign' => $campaign,
            'banners' => $banners,
            'segments' => $segments,
            'selectedSegments' => $selectedSegments,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param CampaignRequest|Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(CampaignRequest $request)
    {
        $campaign = new Campaign();
        $campaign->fill($request->all());
        $campaign->save();

        foreach ($request->get('segments') as $r) {
            /** @var CampaignSegment $campaignSegment */
            $campaignSegment = new CampaignSegment();
            $campaignSegment->code = $r['code'];
            $campaignSegment->provider = $r['provider'];
            $campaignSegment->campaign_id = $campaign->id;
            $campaignSegment->save();

            if ($campaign->active) {
                dispatch(new CacheSegmentJob($campaignSegment));
            }
        }

        return redirect(route('campaigns.index'))->with('success', 'Campaign created');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Campaign  $campaign
     * @return \Illuminate\Http\Response
     */
    public function show(Campaign $campaign)
    {
        return view('campaigns.show', [
            'campaign' => $campaign,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Campaign $campaign
     * @param SegmentAggregator $segmentAggregator
     * @return \Illuminate\Http\Response
     */
    public function edit(Campaign $campaign, SegmentAggregator $segmentAggregator)
    {
        $campaign->fill(old());
        $banners = Banner::all();

        try {
            $segments = $segmentAggregator->list();
        } catch (SegmentException $e) {
            $segments = new Collection();
            flash('Unable to fetch list of segments, please check the application configuration.')->error();
            \Log::error($e->getMessage());
        }

        return view('campaigns.edit', [
            'campaign' => $campaign,
            'banners' => $banners,
            'segments' => $segments,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param CampaignRequest|Request $request
     * @param  \App\Campaign $campaign
     * @return \Illuminate\Http\Response
     */
    public function update(CampaignRequest $request, Campaign $campaign)
    {
        $campaign->fill($request->all());
        $shouldCache = $campaign->isDirty('active');
        $campaign->save();

        foreach ($request->get('segments', []) as $r) {
            /** @var CampaignSegment $campaignSegment */
            $campaignSegment = CampaignSegment::findOrNew($r['id']);
            $campaignSegment->code = $r['code'];
            $campaignSegment->provider = $r['provider'];
            $campaignSegment->campaign_id = $campaign->id;
            $campaignSegment->save();

            if ($campaign->active && $shouldCache) {
                dispatch(new CacheSegmentJob($campaignSegment));
            }
        }

        CampaignSegment::destroy($request->get('removedSegments'));
        return redirect(route('campaigns.index'))->with('success', 'Campaign updated');
    }

    /**
     * @param Request $r
     * @param DimensionMap $dm
     * @param PositionMap $pm
     * @param AlignmentMap $am
     * @param SegmentAggregator $sa
     * @return \Illuminate\Http\JsonResponse
     */
    public function showtime(
        Request $r,
        DimensionMap $dm,
        PositionMap $pm,
        AlignmentMap $am,
        SegmentAggregator $sa
    ) {
        // validation

        $data = \GuzzleHttp\json_decode($r->get('data'));
        $url = $data->url ?? null;
        if (!$url) {
            return response()
                ->jsonp($r->get('callback'), [
                    'success' => false,
                    'errors' => ['url is required and missing'],
                ]);
        }

        $userId = $data->userId ?? null;
        if (!$userId) {
            return response()
                ->jsonp($r->get('callback'), [
                    'success' => false,
                    'errors' => [],
                ])
                ->setStatusCode(400);
        }

        $campaignIds = Cache::get(Campaign::ACTIVE_CAMPAIGN_IDS, []);
        if (count($campaignIds) == 0) {
            return response()
                ->jsonp($r->get('callback'), [
                    'success' => true,
                    'data' => [],
                ]);
        }
        /** @var Campaign $campaign */
        $campaign = Cache::tags(Campaign::CAMPAIGN_TAG)->get($campaignIds[0]);
        $banner = $campaign->banner;
        if (!$banner) {
            return response()
                ->jsonp($r->get('callback'), [
                    'success' => false,
                    'errors' => ["active campaign [{$campaign->uuid}] has no banner set"],
                ]);
        }

        // segment

        foreach ($campaign->segments as $campaignSegment) {
            if (!$sa->check($campaignSegment, $userId)) {
                return response()
                    ->jsonp($r->get('callback'), [
                        'success' => true,
                        'data' => [],
                    ]);
            }
        }

        $positions = $pm->positions();
        $dimensions = $dm->dimensions();
        $alignments = $am->alignments();

        return response()
            ->jsonp($r->get('callback'), [
                'success' => true,
                'errors' => [],
                'data' => [
                    View::make('banners.preview', [
                        'banner' => $banner,
                        'campaign' => $campaign,
                        'positions' => $positions,
                        'dimensions' => $dimensions,
                        'alignments' => $alignments,
                    ])->render(),
                ],
            ]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Campaign  $campaign
     * @return \Illuminate\Http\Response
     */
    public function destroy(Campaign $campaign)
    {
        //
    }
}
