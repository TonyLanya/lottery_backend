<?php

namespace App\Http\Controllers\API;

use App\Admin;
use App\betlist;
use App\round;
use App\roundlist;
use App\slotstate;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use JWTAuth;
use DateTime;

class ApiAgentController extends Controller
{

    public function getHomepageInfo(Request $request)
    {
        try{
            $cround = round::get()->first();
            $lround = roundlist::get()->last();
            $passedround = roundlist::where('rightNumber', '!=', 'null')->orderBy('id', 'desc')->take(10)->get();
            return response()->json(['message' => "HomePage Info", 'data' => ["current" => $cround, 'last' => $lround, 'passedround'=> $passedround], 'response_code' =>1], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Request Error', 'data' => null, 'response_code' => 0], 200);
        }
    }

    public function getbetinfo($betinfo)
    {
        $bets = explode("%", $betinfo);
        $betNumbers = array();
        for ($i = 0; $i < count($bets); $i ++)
        {
            $res = explode('&', $bets[$i]);
            array_push($betNumbers, $res);
        }
        return $betNumbers;
    }

    public function getMyBetInfo(Request $request)
    {
        try{
            $user = JWTAuth::parseToken()->authenticate();
            $betlists = betlist::where('name',$user->name)->orderBy('id','desc')->take(10)->get();
            foreach ($betlists as $k => $betlist) {
                $betstate = $betlist->betNumber;
                $data = $this->getbetinfo($betstate);
                $reslist = array();
                for ($i = 0; $i < count($data); $i ++) {
                    $res = "Number #" . $data[$i][0] . "=" . "MYR " . $data[$i][1];
                    $reslist[$i] = $res;
                }
                $betlists[$k]['roundinfo'] = str_replace("Round", "R", $betlists[$k]['round']);
                $betlists[$k]['betstate'] = $reslist;
            }
            return response()->json(['message' => 'My Bet Info', 'data' => $betlists, 'response_code' => 1], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Request Error', 'data' => null, 'response_code' => 0], 200);
        }
    }

    public function getReportInfo(Request $request)
    {
        try{
            $user = JWTAuth::parseToken()->authenticate();
            if ($request->datefilter) {
                $slices = explode(" - ", $request->datefilter);
                $from = $slices[0];
                $to = $slices[1];
            }
            if ($request->roundname && $request->datefilter) {
                $betlists = betlist::where('name', $user->name)->where('wls', '!=', '')->where('round', '=', $request->roundname)->whereBetween('created_at', [date($from), date($to)])->orderBy('id', 'desc')->take(10)->get();
            } else if($request->roundname) {
                $betlists = betlist::where('name', $user->name)->where('wls', '!=', '')->where('round', '=', $request->roundname)->orderBy('id', 'desc')->take(10)->get();
            } else if($request->datefilter) {
                $betlists = betlist::where('name', $user->name)->where('wls', '!=', '')->whereBetween('created_at', [date($from), date($to)])->orderBy('id', 'desc')->take(10)->get();
            } else {
                $betlists = betlist::where('name',$user->name)->where('wls','!=','')->orderBy('id','desc')->take(10)->get();
            }
            foreach ($betlists as $k => $betlist) {
                $betstate = $betlist->betNumber;
                $data = $this->getbetinfo($betstate);
                $reslist = array();
                for ($i = 0; $i < count($data); $i ++) {
                    $res = "Number #" . $data[$i][0] . "=" . "MYR " . $data[$i][1];
                    $reslist[$i] = $res;
                }
                $betlists[$k]['betstate'] = $reslist;
            }
            return response()->json(['message' => 'My Bet Info', 'request' => $request->roundname, 'data' => $betlists, 'response_code' => 1], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Request Error', 'data' => null, 'response_code' => 0], 200);
        }
    }

    public function getUserData(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            return response()->json(['message' => 'Confirm Bet', 'data' => $user, 'response_code' => 1], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Request Error', 'data' => null, 'response_code' => 0], 200);
        }
    }

    public function confirmBet(Request $request)
    {
        try{
            $user = JWTAuth::parseToken()->authenticate();
            if ($user->amount < $request->totalbet) {
                return response()->json(['message' => 'Not Enough Cash', 'data'=> null, 'response_code' => 0], 200);
            }
            $duplicate = false;
            $betstate = $request->betstate;
            $nround = round::get()->first();
            $lastbet = betlist::where('name', $user->name)->where('round', $nround->roundname)->orderBy('receiptNumber', 'desc')->get()->first();
            $betlist = new betlist();
            $betlist->name = $user->name;
            $betlist->betNumber = $betstate;
            $betlist->total = $request->totalbet;
            $betlist->round = $nround->roundname;
            if (!$lastbet) {
                $betlist->receiptNumber = 1;
            } else {
                $betlist->receiptNumber = $lastbet->receiptNumber + 1;
            }
            $betlist->save();
            $nround->totalbet = $nround->totalbet + $request->totalbet;
            $nround->save();
            $us = Admin::where('name', $user->name)->get()->first();
            $us->amount = $us->amount - $request->totalbet;
            $us->save();
            $au = Admin::where('name', 'Tony')->get()->first();
            $au->amount = $au->amount + $request->totalbet;
            $au->save();
            $data = $this->getbetinfo($betstate);
            for ($i = 0; $i < count($data); $i ++) {
                $nslot = slotstate::get()->first();
                if ( is_numeric($data[$i][0]) ) {
                    $changecol = 's' . $data[$i][0];
                } else {
                    switch ( $data[$i][0] ) {
                        case "1st":
                            $changecol = '1st';
                            break;
                        case "2nd":
                            $changecol = '2nd';
                            break;
                        case "3rd":
                            $changecol = '3rd';
                            break;
                        case "1-18":
                            $changecol = 'f118';
                            break;
                        case "EVEN":
                            $changecol = 'even';
                            break;
                        case "BLACK":
                            $changecol = 'black';
                            break;
                        case "RED":
                            $changecol = 'red';
                            break;
                        case "ODD":
                            $changecol = 'odd';
                            break;
                        case "19-36":
                            $changecol = 'f1936';
                            break;
                        default:
                            $duplicate = true;
                            break;
                    }
                }
                if ( $duplicate == false ) {
                    $slices = explode("|", $nslot->value($changecol));
                    $namount = $slices[1];
                    $amount = $namount + intval($data[$i][1]);
                    $newval = '' . $slices[0] . '|' . $amount;
                    DB::table('slotstates')
                        ->where('id', '>', 0)
                        ->update(array($changecol => $newval));
                } else {
                    $slices = explode("|", $data[$i][0]);
                    $amount = floatval($data[$i][1]) / count($slices);
                    for( $j = 0; $j < count($slices); $j ++ ) {
                        $changecol = 's' . $slices[$j];
                        $sls = explode("|", $nslot->value($changecol));
                        $oldamount = $sls[1];
                        $newamount = $oldamount + $amount;
                        $newval = '' . $sls[0] . "|" . $newamount;
                        DB::table('slotstates')
                            ->where('id', '>', 0)
                            ->update(array($changecol => $newval));
                    }
                }
            }
            $betlist['round'] = str_replace("Round", "R", $betlist['round']);
            $betlist['betNumbers'] = $this->getbetinfo($betlist['betNumber']);
            return response()->json(['message' => 'Confirm Bet', 'data' => $betlist, 'response_code' => 1], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Request Error', 'data'=> $e, 'response_code' => 0], 200);
        }
    }

    public function cancelBet(Request $request)
    {
        try{
            $user = JWTAuth::parseToken()->authenticate();
            $duplicate = false;
            $betid = $request->id;
            $nbet = betlist::where('id', $betid)->get()->first();
            $betstate = $nbet->betNumber;
            $nround = round::get()->first();
            if ($nround->roundname != $nbet->round) {
                return response()->json(['message' => 'This is finished. Can not cancel. You can only cancel current round bets.', 'data'=> null, 'response_code' => 0], 200);
            }
            $nround->totalbet = $nround->totalbet - $nbet->total;
            $nround->save();
            $us = Admin::where('name', $user->name)->get()->first();
            $us->amount = $us->amount + $request->totalbet;
            $us->save();
            $au = Admin::where('name', 'Tony')->get()->first();
            $au->amount = $au->amount - $request->totalbet;
            $au->save();
            $data = $this->getbetinfo($betstate);
            for ($i = 0; $i < count($data); $i ++) {
                $nslot = slotstate::get()->first();
                if ( is_numeric($data[$i][0]) ) {
                    $changecol = 's' . $data[$i][0];
                } else {
                    switch ( $data[$i][0] ) {
                        case "1st":
                            $changecol = '1st';
                            break;
                        case "2nd":
                            $changecol = '2nd';
                            break;
                        case "3rd":
                            $changecol = '3rd';
                            break;
                        case "1-18":
                            $changecol = 'f118';
                            break;
                        case "EVEN":
                            $changecol = 'even';
                            break;
                        case "BLACK":
                            $changecol = 'black';
                            break;
                        case "RED":
                            $changecol = 'red';
                            break;
                        case "ODD":
                            $changecol = 'odd';
                            break;
                        case "19-36":
                            $changecol = 'f1936';
                            break;
                        default:
                            $duplicate = true;
                            break;
                    }
                }
                if ( $duplicate == false ) {
                    $slices = explode("|", $nslot->value($changecol));
                    $namount = $slices[1];
                    $amount = $namount - intval($data[$i][1]);
                    $newval = '' . $slices[0] . '|' . $amount;
                    DB::table('slotstates')
                        ->where('id', '>', 0)
                        ->update(array($changecol => $newval));
                } else {
                    $slices = explode("|", $data[$i][0]);
                    $amount = floatval($data[$i][1]) / count($slices);
                    for( $j = 0; $j < count($slices); $j ++ ) {
                        $changecol = 's' . $slices[$j];
                        $sls = explode("|", $nslot->value($changecol));
                        $oldamount = $sls[1];
                        $newamount = $oldamount - $amount;
                        $newval = '' . $sls[0] . "|" . $newamount;
                        DB::table('slotstates')
                            ->where('id', '>', 0)
                            ->update(array($changecol => $newval));
                    }
                }
            }
            $nbet->delete();
            return response()->json(['message' => 'Cancel Bet', 'data' => null, 'response_code' => 1], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Request Error', 'data'=> $e, 'response_code' => 0], 200);
        }
    }

    public function getResultInfo(Request $request)
    {
        try{
            $passedround = roundlist::where('rightNumber', '!=', 'null')->orderBy('id', 'desc')->take(50)->get();
            return response()->json(['message' => "Result Info", 'data' => ['passedround'=> $passedround], 'response_code' =>1], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Request Error', 'data' => null, 'response_code' => 0], 200);
        }
    }

    public function getCurrentInfo(Request $request)
    {
        try{
            $cround = round::get()->first();
            if ($cround) {
                return response()->json(['message' => 'Current Round Info', 'data' => $cround, 'response_code' => 1], 200);
            } else {
                return response()->json(['message' => 'No Round', 'data' => null, 'response_code' => 0], 200);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Request Error', 'data' => null, 'response_code' => 0], 200);
        }
    }

    public function getLastRoundInfo(Request $request)
    {
        try{
            $lround = roundlist::get()->last();
            if ($lround) {
                return response()->json(['message' => 'Last Round Info', 'data' => $lround, 'response_code' => 1], 200);
            } else {
                return response()->json(['message' => 'No Round', 'data' => null, 'response_code' => 0], 200);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Request Error', 'data' => null, 'response_code' => 0], 200);
        }
    }

    //
    public function assignAgent(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!empty($user->id)) {
            try {
                $customer = User::where('id', $user->id)->select('latitude', 'longitude')->get();
                if (count($customer) > 0) {
                    $lat = 0;
                    $lon = 0;
                    foreach ($customer as $val) {
                        $lat = $val->latitude;
                        $lon = $val->longitude;
                    }
                    $agents = DB::table("users")
                            ->select("users.*", DB::raw("6371 * acos(cos(radians(" . $lat . "))
                         * cos(radians(users.latitude))
                       * cos(radians(users.longitude) - radians(" . $lon . "))
                     + sin(radians(" . $lat . "))
                     * sin(radians(users.latitude))) AS distance"))
                            ->where(['users.usertype' => 'agent', 'users.isAvailable' => 1])
                            ->orderBy('distance', 'asc')
                            ->take(3)
                            ->get();
                    return response()->json(['message' => 'Nearest agents', 'data' => $agents, 'response_code' => 1], 200);
                } else {
                    return response()->json(['message' => 'Custmor is not exist', 'data' => null, 'response_code' => 0], 200);
                }
            } catch (\Exception $exception) {
                return response()->json(['message' => 'Server Error', 'data' => $exception, 'response_code' => 0], 500);
            }
        } else {
            return response()->json(['message' => 'Customer id is missing', 'data' => null, 'response_code' => 0], 200);
        }
    }

    /**
    *
    *
    *
    *
    *
    */

    public function handOverJob(Request $request)
    {
        $validator = Validator::make($request->all(), [
                    'agentid' => 'required',
                    'customerid' => 'required',
                    'jobid' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Submit error', 'data' => $validator->errors(), 'response_code' => 0], 200);
        }
        try {
            $ajob = new AssignJob();
            $ajob->agent_id = $request->agentid;
            $ajob->customer_id = $request->customerid;
            $ajob->job_id = $request->jobid;
            $result = $ajob->save();
            if ($result) {
                return response()->json(['message' => 'job is assigned', 'data' => AssignJob::findOrFail($ajob->id), 'response_code' => 1], 200);
            } else {
                return response()->json(['message' => 'job  assigning error', 'data' => null, 'response_code' => 0], 200);
            }
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Server Error', 'data' => $exception, 'response_code' => 0], 500);
        }
    }

    /**
     *
     *
     * View assigned job by a specific agent
     *
     *
     *
     */
    public function assignedJobView(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        if (!$request->has('assignedjobid')) {
            return response()->json(['message' => 'please submit assign job ID', 'data' => null, 'response_code' => 0], 200);
        }
        try {
            $job = User::join('jobs', 'users.id', '=', 'jobs.user_id')
                            ->join('assignJobs', 'users.id', '=', 'assignJobs.customer_id')
                            ->where(['assignJobs.job_id' => $request->assignedjobid])->get();
            if (count($job) > 0) {
                return response()->json(['message' => 'job is assigned', 'data' => $job, 'response_code' => 1], 200);
            } else {
                return response()->json(['message' => 'This job is not assigned yet', 'data' => null, 'response_code' => 0], 200);
            }
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Server Error', 'data' => null, 'response_code' => 0], 500);
        }
    }

    /**
     *
     *
     * Update   assigned job status i.e reject or accept
     *
     *
     */
    public function jobAction(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if ($user->usertype == 'customer') {
            return response()->json(['message' => 'You must be agent', 'data' => null, 'response_code' => 0], 200);
        }
        $validator = Validator::make($request->all(), [
                    'status' => 'required',
                    'jobid' => 'required',
                    'costomerId' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Some fields missing', 'data' => $validator->errors(), 'response_code' => 0], 200);
        }

        try {
            if ($ajob = AssignJob::where(['job_id'=>$request->jobid,'agent_id'=>$user->id,'customer_id'=>$request->costomerId,])->first()) {
                $ajob->jobstatus = $request->status;
                $ajob->update();
                $action = $ajob;
            } else {
                $action = new AssignJob();
                $action->jobstatus = $request->status;
                $action->job_id = $request->jobid;
                $action->agent_id = $user->id;
                $action->customer_id = $request->costomerId;
                $action->save();
            }
            if ($action->id) {
                return response()->json(['message' => 'This job is updated', 'data' => $action, 'response_code' => 1], 200);
            } else {
                return response()->json(['message' => 'This job status is not updated', 'data' => null, 'response_code' => 0], 200);
            }
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Server Error', 'data' => $exception, 'response_code' => 0], 500);
        }
        return response()->json(['message' => 'Some fields missing', 'data' => null, 'response_code' => 0], 200);
    }

    /**
     *
     *
     * Update job status 1 :: It's complete
     *
     *
     */
    public function completeJob(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if ($user->usertype == 'customer') {
            return response()->json(['message' => 'You must be agent', 'data' => null, 'response_code' => 0], 200);
        }
        $validator = Validator::make($request->all(), [
                    'jobid' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Some fields missing', 'data' => $validator->errors(), 'response_code' => 0], 200);
        }

        try {
          $job = JobsModel::where('id', '=', $request->jobid)->first();
          $job->job_status = 1;
          $job->update();
          $ajob = AssignJob::where(["job_id" => $job->id, "agent_id" => $user->id])->get()->first();
          $ajob->jobstatus = 4;
          $ajob->update();
          return response()->json(['message' => 'Complete this job success', 'response_code' => 1], 200);
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Server Error', 'response_code' => 0], 500);
        }
        return response()->json(['message' => 'Some fields missing', 'response_code' => 0], 200);
    }

    /**
     *
     *
     * Update customer accept agent
     *
     *
     */
    public function acceptAgent(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if ($user->usertype == 'agent') {
            return response()->json(['message' => 'You must be customer', 'data' => null, 'response_code' => 0], 200);
        }
        $validator = Validator::make($request->all(), [
                    'status' => 'required',
                    'jobid' => 'required',
                    'agentId' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Some fields missing', 'data' => $validator->errors(), 'response_code' => 0], 200);
        }

        try {
            if ($ajob = AssignJob::where(['job_id'=>$request->jobid,'agent_id'=>$request->agentId,'customer_id'=>$user->id,])->first()) {
                $ajob->jobstatus = $request->status;
                $ajob->update();
                AssignJob::where(['job_id'=>$request->jobid,'customer_id'=>$user->id,])->where('agent_id', '!=', $request->agentId)->delete();
                DocumentsModel::where('job_id', '=', $request->jobid)->where('user_id', '!=', $request->agentId)->where('user_id', '!=', $user->id)->delete();
                QuotationModel::where('agent_id', '!=', $request->agentId)->where('job_id', '=', $request->jobid)->delete();
                $action = $ajob;
            } else {
                return response()->json(['message' => 'There is not that job', 'data' => null, 'response_code' => 0], 200);
            }
            if ($action->id) {
                return response()->json(['message' => 'This job is updated', 'data' => $action, 'response_code' => 1], 200);
            } else {
                return response()->json(['message' => 'This job status is not updated', 'data' => null, 'response_code' => 0], 200);
            }
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Server Error', 'data' => $exception, 'response_code' => 0], 500);
        }
        return response()->json(['message' => 'Some fields missing', 'data' => null, 'response_code' => 0], 200);
    }

    /**
     *
     *
     * view all  job assigned to a  particular agent
     *
     * which are not taken any action
     *
     *
     */
    public function acceptedJobList(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        try {
            if ($user->usertype == 'agent') {
                $jobList = AssignJob::join('jobs', 'jobs.id', '=', 'assignJobs.job_id')
                                ->join('users', 'assignJobs.customer_id', '=', 'users.id')
                                ->where(['assignJobs.jobstatus' => '1','assignJobs.agent_id' => $user->id])
                                ->get();
                if (count($jobList) > 0) {
                    return response()->json(['message' => 'This is job list', 'data' => $jobList, 'response_code' => 1], 200);
                } else {
                    return response()->json(['message' => 'This agent is no assigned jobs list', 'data' => null, 'response_code' => 0], 200);
                }
            } else {
                $jobList = AssignJob::join('jobs', 'jobs.id', '=', 'assignJobs.job_id')
                                ->join('users', 'assignJobs.customer_id', '=', 'users.id')
                                ->where(['assignJobs.customer_id' => $user->id , 'assignJobs.jobstatus' => '1'])->get();
                if (count($jobList) > 0) {
                    return response()->json(['message' => 'This is job list', 'data' => $jobList, 'response_code' => 1], 200);
                } else {
                    return response()->json(['message' => 'This agent is no assigned jobs list', 'data' => null, 'response_code' => 0], 200);
                }
            }
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Server Error', 'data' => null, 'response_code' => 0], 500);
        }
    }
    /**
     *
     *
     * view all  job assigned to a  particular agent
     *
     * which are not taken any action
     *
     *
     */
    public function allJobView(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        try {
            $jobs = User::join('assignJobs', 'users.id', '=', 'assignJobs.agent_id')
                    ->join('jobs', 'assignJobs.job_id', '=', 'jobs.id')
                    ->where(['assignJobs.agent_id' => $user->id, 'assignJobs.jobstatus' => null])
                    ->select()
                    ->addSelect('assignJobs.id as assignJobsId')
                    ->addSelect('assignJobs.updated_at as assUpdatedAt')
                    ->get();
            foreach ($jobs as $k => $job) {
              $customer = User::where('users.id', '=', $job['customer_id'])->get()->first();
              $jobs[$k]['image'] = $customer['image'];
            }
            if (count($jobs) > 0) {
                return response()->json(['message' => 'All assigned job', 'data' => $jobs, 'response_code' => 1], 200);
            } else {
                return response()->json(['message' => 'No job is assigned to this agent', 'data' => null, 'response_code' => 0], 200);
            }
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Server Error', 'data' => null, 'response_code' => 0], 500);
        }
    }
    /**
     *
     *
     *
     *
     * Agent history i.e view all job that are accepted are rejected by particular agent
     *
     *
     *
     */
    public function agentHistory(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        try {
            $job = User::join('assignJobs', 'users.id', '=', 'assignJobs.agent_id')
                    ->join('jobs', 'assignJobs.job_id', '=', 'jobs.id')
                    ->where(['assignJobs.agent_id' => $user->id])
                    ->where('assignJobs.jobstatus', '<>', null)
                    ->where(['jobs.job_status' => '1'])
                    ->get();
            if (count($job) > 0) {
                return response()->json(['message' => 'Agent History', 'data' => $job, 'response_code' => 1], 200);
            } else {
                return response()->json(['message' => 'No job is completed by this agent', 'data' => null, 'response_code' => 0], 200);
            }
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Server Error', 'data' => null, 'response_code' => 0], 500);
        }
    }


    /**
     *
     *
     *
     *
     * Get Completed Job list
     *
     *
     *
     */
    public function agentCompletedJob(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        try {
          $job = AssignJob::join('jobs', 'jobs.id', '=', 'assignJobs.job_id')
                                ->join('users', 'assignJobs.customer_id', '=', 'users.id')
                                ->where(['assignJobs.jobstatus' => '4','assignJobs.agent_id' => $user->id])
                                ->get();
          if (count($job) > 0) {
              return response()->json(['message' => 'Agent Completed Jobs', 'data' => $job, 'response_code' => 1], 200);
          } else {
              return response()->json(['message' => 'No job is completed by this agent', 'data' => null, 'response_code' => 0], 200);
          }
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Server Error', 'data' => null, 'response_code' => 0], 500);
        }
    }

    /**
     *
     *
     *
     *
     * Get Completed Job list by Customer
     *
     *
     *
     */
    public function customerGetCompletedJob(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        try {
            $job = User::join('assignJobs', 'users.id', '=', 'assignJobs.agent_id')
                    ->join('jobs', 'assignJobs.job_id', '=', 'jobs.id')
                    ->where(['assignJobs.customer_id' => $user->id])
                    ->where('assignJobs.jobstatus', '=', '4')
                    ->where(['jobs.job_status' => '1'])
                    ->get();
            if (count($job) > 0) {
                return response()->json(['message' => 'Agent Completed Jobs for this customer', 'data' => $job, 'response_code' => 1], 200);
            } else {
                return response()->json(['message' => 'No job is completed by this agent', 'data' => null, 'response_code' => 0], 200);
            }
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Server Error', 'data' => null, 'response_code' => 0], 500);
        }
    }

    //----------------------quotaion Modules
    // add quotation to jobs.
    public function addQuotation(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if ($user->usertype == 'customer') {
            return response()->json(['message' => 'this is no agent', 'data' => null, 'response_code' => 0], 200);
        }
        $validator = Validator::make($request->all(), [
                    'quotation_price' => 'required',
                    'job_id' => 'required',
                    'assign_id' => 'required',
                    'quotation_description' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Insert data error', 'data' => $validator->errors(), 'response_code' => 0], 200);
        }
        $save_data = array(
          'agent_id' => $user->id,
          'job_id' => $request->job_id,
          'quotation_price' => $request->quotation_price,
          'quotation_description' => $request->quotation_description
        );
        try {
            $quot = QuotationModel::where(['agent_id' => $user->id, 'job_id' => $request->job_id ])
            ->get();
            if (count($quot) > 0) {
                $quot[0]->quotation_price = $request->quotation_price;
                $quot[0]->quotation_description = $request->quotation_description;
                $quot[0]->save();
                /*$job = JobsModel::where("id", "=", $request->job_id)->get()->first();
                $job->quotation_price = $request->quotation_price;
                $job->update();*/
                // Update documents table
                foreach ($request->documents as $docurl) {
                    $document = new DocumentsModel();
                    $document->user_id = $user->id;
                    $document->job_id = $request->job_id;
                    $document->fileName = $docurl;
                    $document->save();
                }
                return response()->json(['message' => 'Successfully update quotation', 'data' => $quot[0], 'response_code' => 1], 200);
            }
            if ($question = QuotationModel::create($save_data)) {
                $ajob = AssignJob::findOrFail($request->assign_id);
                $ajob->quotation_id = $question->id;
                $ajob->save();
                $job = JobsModel::where("id", "=", $request->job_id)->get()->first();
                $job->quotation_price = $request->quotation_price;
                $job->update();
                // Update documents table
                foreach ($request->documents as $docurl) {
                    $document = new DocumentsModel();
                    $document->user_id = $user->id;
                    $document->job_id = $request->job_id;
                    $document->fileName = $docurl;
                    $document->save();
                }
                return response()->json(['message' => 'Successfully added quotation', 'data' => $question, 'response_code' => 1], 200);
            } else {
                return response()->json(['message' => 'Addition is failed', 'data' => null, 'response_code' => 0], 200);
            }
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Server Error', 'data' => $exception, 'response_code' => 0], 500);
        }
    }

    // get quotation
    public function getQuotation(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if ($request->has('quotation_id')) {
            $quotation = QuotationModel::join('jobs', 'jobs.id', '=', 'quotations.job_id')
                            ->where(['quotations.id' => $request->quotation_id])->get();
            return response()->json(['message' => 'Get quotation by quotation ID', 'data' => $quotation, 'response_code' => 1], 200);
        } else {
            if ($user->usertype == 'agent') {
                //$quotations = QuotationModel::join('jobs', 'jobs.id', '=', 'quotations.job_id')
                //                ->where(['quotations.agent_id' => $user->id])->get();
                return response()->json(['message' => 'Get quotation list by agent id', 'data' => null, 'response_code' => 1], 200);
            } else {
                $quotations = JobsModel::join('quotations', 'jobs.id', '=', 'quotations.job_id')
                                ->join('users', 'quotations.agent_id', '=', 'users.id')
                                ->where(['jobs.user_id' => $user->id])
                                ->select()
                                ->addSelect('quotations.id as quotation_id')
                                ->get();
                $rets = [];
                foreach ($quotations as $k => $quotation) {
                  $res = AssignJob::where('quotation_id', $quotation["quotation_id"])->get()->first();
                  if ($res == null) {
                  } else if($res["jobstatus"] != 3){
                  } else {
                    array_push($rets, $quotations[$k]);
                  }
                }
                return response()->json(['message' => 'Get quotation list by customer id', 'data' => $rets, 'response_code' => 1], 200);
            }
        }
    }

    //RENEW project
    public function renewJob(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$request->has('jobid')) {
            return response()->json(['message' => 'No jobid', 'data' => null, 'response_code' => 0], 200);
        }
        try {
          $ojob = JobsModel::where(['id'=>$request->jobid])->first();
          $format = 'Y-m-d H:i:s';
          $exp_d = DateTime::createFromFormat($format, $ojob->expired_date);
          $exp_d->modify('+1 year');
          $ojob->expired_date = $exp_d;
          $ojob->job_status = 0;
          $ojob->save();
          $ajob = AssignJob::where(['job_id'=>$ojob->id,'agent_id'=>$request->agent_id,'customer_id'=>$user->id,])->first();
          $ajob->jobstatus = null;
          $ajob->save();
          return response()->json(['message' => 'jobs Successfully renewed', 'data' => null, 'response_code' => 1], 200);
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Server Error', 'data' => $exception, 'response_code' => 0], 500);
        }
    }

    //getRatings
    public function getRatings(Request $request)
    {
      $user = JWTAuth::parseToken()->authenticate();

    }

    //addRatings
    public function addRatings(Request $request)
    {
      $user = JWTAuth::parseToken()->authenticate();
      if (!$request->has('agent_id')) {
          return response()->json(['message' => 'No agent_id', 'data' => null, 'response_code' => 0], 200);
      }
      if (!$request->has('rating')) {
          return response()->json(['message' => 'No rating', 'data' => null, 'response_code' => 0], 200);
      }
      $rating = new rating();
        $rating->user_id = $request->agent_id;
        $rating->rating = $request->rating;
        $rating->save();
        return response()->json(['message' => 'Add Rating success', 'response_code' => 1], 200);
      try
      {
        $rating = new rating();
        //$ratings->user_id = $request->agent_id;
        $ratings->rating = $request->rating;
        $rating->save();
        return response()->json(['message' => 'Add Rating success', 'response_code' => 1], 200);
      } catch (\Exception $exception) {
          return response()->json(['message' => 'Server Error', 'data' => $exception, 'response_code' => 0], 500);
      }
    }
}
