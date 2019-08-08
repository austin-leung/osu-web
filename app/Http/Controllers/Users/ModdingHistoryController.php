<?php

/**
 *    Copyright (c) ppy Pty Ltd <contact@ppy.sh>.
 *
 *    This file is part of osu!web. osu!web is distributed with the hope of
 *    attracting more community contributions to the core ecosystem of osu!.
 *
 *    osu!web is free software: you can redistribute it and/or modify
 *    it under the terms of the Affero GNU General Public License version 3
 *    as published by the Free Software Foundation.
 *
 *    osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
 *    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *    See the GNU Affero General Public License for more details.
 *
 *    You should have received a copy of the GNU Affero General Public License
 *    along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\BeatmapDiscussion;
use App\Models\BeatmapDiscussionPost;
use App\Models\BeatmapDiscussionVote;
use App\Models\BeatmapsetEvent;
use App\Models\User;
use App\Models\UsernameChangeHistory;
use Illuminate\Pagination\LengthAwarePaginator;

class ModdingHistoryController extends Controller
{
    protected $actionPrefix = 'modding-history-';
    protected $section = 'user';

    protected $isModerator;
    protected $isKudosuModerator;
    protected $searchParams;
    protected $user;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->isModerator = priv_check('BeatmapDiscussionModerate')->can();
            $this->isKudosuModerator = priv_check('BeatmapDiscussionAllowOrDenyKudosu')->can();
            $this->user = User::lookup(request('user'), null, $this->isModerator);

            if ($this->user === null) {
                $change = UsernameChangeHistory::visible()
                    ->where('username_last', request('user'))
                    ->orderBy('change_id', 'desc')
                    ->first();

                if ($change !== null) {
                    $this->user = User::lookup($change->user_id, 'id');
                }
            }

            if ($this->user === null || $this->user->isBot() || !priv_check('UserShow', $this->user)->can()) {
                return response()->view('users.show_not_found')->setStatusCode(404);
            }

            if ((string) $this->user->user_id !== (string) request('user')) {
                return ujs_redirect(route(
                    $request->route()->getName(),
                    array_merge(['user' => $this->user->user_id], $request->query())
                ));
            }

            $this->searchParams = array_merge(['user' => $this->user->user_id], request()->query());
            $this->searchParams['is_moderator'] = $this->isModerator;
            $this->searchParams['is_kudosu_moderator'] = $this->isKudosuModerator;

            if (!$this->isModerator) {
                $this->searchParams['with_deleted'] = false;
            }

            return $next($request);
        });

        parent::__construct();
    }

    public function index()
    {
        $user = $this->user;

        $this->searchParams['limit'] = 10;
        $this->searchParams['sort'] = 'id_desc';
        $this->searchParams['with_deleted'] = $this->isModerator;

        $discussions = BeatmapDiscussion::search($this->searchParams);
        $discussions['items'] = $discussions['query']->with([
            'beatmap',
            'beatmapset',
            'startingPost',
        ])->get();

        $posts = BeatmapDiscussionPost::search($this->searchParams);
        $posts['items'] = $posts['query']->with([
            'beatmapDiscussion.beatmap',
            'beatmapDiscussion.beatmapset',
        ])->get();

        $events = BeatmapsetEvent::search($this->searchParams);
        if ($this->isModerator) {
            $events['items'] = $events['query']->with(['beatmapset' => function ($query) {
                $query->withTrashed();
            }])->with('beatmapDiscussion')->get();
        } else {
            $events['items'] = $events['query']->with(['beatmapset', 'beatmapDiscussion'])->get();
        }

        $votes['items'] = BeatmapDiscussionVote::recentlyGivenByUser($user->getKey());
        $receivedVotes['items'] = BeatmapDiscussionVote::recentlyReceivedByUser($user->getKey());

        $userIdSources = [
            $discussions['items']
                ->pluck('user_id')
                ->toArray(),
            $posts['items']
                ->pluck('user_id')
                ->toArray(),
            $posts['items']
                ->pluck('last_editor_id')
                ->toArray(),
            $events['items']
                ->pluck('user_id')
                ->toArray(),
            $votes['items']
                ->pluck('user_id')
                ->toArray(),
            $receivedVotes['items']
                ->pluck('user_id')
                ->toArray(),
        ];

        $userIds = [];

        foreach ($userIdSources as $source) {
            $userIds = array_merge($userIds, $source);
        }

        $userIds = array_values(array_filter(array_unique($userIds)));

        $users = User::whereIn('user_id', $userIds)
            ->with('userGroups')
            ->default()
            ->get();

        $perPage = [
            'recentlyReceivedKudosu' => 5,
        ];

        $extras = [];

        foreach ($perPage as $page => $n) {
            // Fetch perPage + 1 so the frontend can tell if there are more items
            // by comparing items count and perPage number.
            $extras[$page] = $this->getExtra($user, $page, $n + 1);
        }

        $jsonChunks = [
            'extras' => $extras,
            'perPage' => $perPage,
            'user' => json_item(
                $user,
                'User',
                [
                    "statistics:mode({$user->playmode})",
                    'active_tournament_banner',
                    'badges',
                    'follower_count',
                    'graveyard_beatmapset_count',
                    'groups',
                    'loved_beatmapset_count',
                    'previous_usernames',
                    'ranked_and_approved_beatmapset_count',
                    'statistics.rank',
                    'statistics.scoreRanks',
                    'support_level',
                    'unranked_beatmapset_count',
                ]
            ),
            'discussions' => json_collection(
                $discussions['items'],
                'BeatmapDiscussion',
                ['startingPost', 'beatmapset', 'current_user_attributes']
            ),
            'events' => json_collection(
                $events['items'],
                'BeatmapsetEvent',
                ['user', 'discussion.startingPost', 'beatmapset', 'beatmapset.user']
            ),
            'posts' => json_collection(
                $posts['items'],
                'BeatmapDiscussionPost',
                ['beatmap_discussion.beatmapset']
            ),
            'votes' => [
                'given' => $votes['items'],
                'received' => $receivedVotes['items'],
            ],
            'users' => json_collection(
                $users,
                'UserCompact',
                ['groups']
            ),
        ];

        return view('users.beatmapset_activities', compact(
            'jsonChunks',
            'user'
        ));
    }

    public function discussions()
    {
        $user = $this->user;

        $search = BeatmapDiscussion::search($this->searchParams);
        $discussions = new LengthAwarePaginator(
            $search['query']->with([
                    'user',
                    'beatmapset',
                    'startingPost',
                ])->get(),
            $search['query']->realCount(),
            $search['params']['limit'],
            $search['params']['page'],
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $search['params'],
            ]
        );

        $showUserSearch = false;

        return view('beatmap_discussions.index', compact('discussions', 'search', 'user', 'showUserSearch'));
    }

    public function events()
    {
        $user = $this->user;

        $search = BeatmapsetEvent::search($this->searchParams);
        if ($this->isModerator) {
            $items = $search['query']->with('user')->with(['beatmapset' => function ($query) {
                $query->withTrashed();
            }])->with('beatmapset.user')->get();
        } else {
            $items = $search['query']->with(['user', 'beatmapset', 'beatmapset.user'])->get();
        }

        $events = new LengthAwarePaginator(
            $items,
            $search['query']->realCount(),
            $search['params']['limit'],
            $search['params']['page'],
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $search['params'],
            ]
        );

        $showUserSearch = false;

        return view('beatmapset_events.index', compact('events', 'user', 'search', 'showUserSearch'));
    }

    public function posts()
    {
        $user = $this->user;

        $search = BeatmapDiscussionPost::search($this->searchParams);
        $posts = new LengthAwarePaginator(
            $search['query']->with([
                    'user',
                    'beatmapset',
                    'beatmapDiscussion',
                    'beatmapDiscussion.beatmapset',
                    'beatmapDiscussion.user',
                    'beatmapDiscussion.startingPost',
                ])->get(),
            $search['query']->realCount(),
            $search['params']['limit'],
            $search['params']['page'],
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $search['params'],
            ]
        );

        return view('beatmap_discussion_posts.index', compact('posts', 'user'));
    }

    public function votesGiven()
    {
        $user = $this->user;

        $search = BeatmapDiscussionVote::search($this->searchParams);
        $votes = new LengthAwarePaginator(
            $search['query']->with([
                    'user',
                    'beatmapDiscussion',
                    'beatmapDiscussion.user',
                    'beatmapDiscussion.beatmapset',
                    'beatmapDiscussion.startingPost',
                ])->get(),
            $search['query']->realCount(),
            $search['params']['limit'],
            $search['params']['page'],
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $search['params'],
            ]
        );

        return view('beatmapset_discussion_votes.index', compact('votes', 'user'));
    }

    public function votesReceived()
    {
        $user = $this->user;
        // quick workaround for existing call
        $this->searchParams['receiver'] = $user->getKey();
        unset($this->searchParams['user']);

        $search = BeatmapDiscussionVote::search($this->searchParams);
        $votes = new LengthAwarePaginator(
            $search['query']->with([
                    'user',
                    'beatmapDiscussion',
                    'beatmapDiscussion.user',
                    'beatmapDiscussion.beatmapset',
                    'beatmapDiscussion.startingPost',
                ])->get(),
            $search['query']->realCount(),
            $search['params']['limit'],
            $search['params']['page'],
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $search['params'],
            ]
        );

        return view('beatmapset_discussion_votes.index', compact('votes', 'user'));
    }

    private function getExtra($user, $page, $options, $perPage = 10, $offset = 0)
    {
        // Grouped by $transformer and sorted alphabetically ($transformer and then $page).
        switch ($page) {
            // KudosuHistory
            case 'recentlyReceivedKudosu':
                $transformer = 'KudosuHistory';
                $query = $user->receivedKudosu()
                    ->with('post', 'post.topic', 'giver', 'kudosuable')
                    ->orderBy('exchange_id', 'desc');
                break;
        }

        if (!isset($collection)) {
            $collection = $query->limit($perPage)->offset($offset)->get();
        }

        return json_collection($collection, $transformer, $includes ?? []);
    }
}
