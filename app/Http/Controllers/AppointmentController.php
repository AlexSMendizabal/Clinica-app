<?php

namespace App\Http\Controllers;

use App\Models\Specialty;
use App\Interfaces\ScheduleServiceInterface;
use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PhpParser\Node\Expr\FuncCall;
use App\Models\CancelledAppointment;

class AppointmentController extends Controller
{
    public function index()
    {
        $role = auth()->user()->role;

        if($role == 'doctor')
        {
            $pendingAppointments = Appointment::where('status','Reservada')->
            where('doctor_id', auth()->id())->paginate(6);

        $confirmedAppointment = Appointment::where('status','Confirmada')->
            where('doctor_id', auth()->id())->paginate(6);

        $oldAppointments = Appointment::whereIn('status',['Atendida','Cancelada'])->
            where('doctor_id', auth()->id())->paginate(6);
        }
        elseif($role == 'patient')
        {
            $pendingAppointments = Appointment::where('status','Reservada')->
            where('patient_id', auth()->id())->paginate(6);

            $confirmedAppointment = Appointment::where('status','Confirmada')->
            where('patient_id', auth()->id())->paginate(6);

         $oldAppointments = Appointment::whereIn('status',['Atendida','Cancelada'])->
            where('patient_id', auth()->id())->paginate(6);
        }

        return view('citas.index', compact('pendingAppointments','confirmedAppointment','oldAppointments', 'role'));
    }

    public function show(Appointment $appointment)
    {
        $role= auth()->user()->role;
        return view('citas.show',compact('appointment','role'));
    }

    public function create(ScheduleServiceInterface $scheduleService)
    {
        $specialties = Specialty::all();
        $specialtyId = old('specialty_id');

        if($specialtyId)
        {
            $specialty  = Specialty::find($specialtyId);
            $doctors = $specialty->users;
        }
        else
        {
            $doctors = collect();
        }

        $date = old('scheduled_date');
        $doctorId = old('doctor_id');
        if($date && $doctorId )
        {
            $intervals =  $scheduleService->getAvailableIntervals($date,$doctorId);
        }
        else
        {
            $intervals = null;
        }

    	return view('citas.create', compact('specialties', 'doctors','intervals'));
    }

    public function store(Request $request, ScheduleServiceInterface $scheduleService )
    {
       /*  dd($request); */

        $rules = [
            'description' => 'required',
            'specialty_id' => 'exists:specialties,id',
            'doctor_id' => 'exists:users,id',
            'scheduled_time' => 'required',
            'type',
        ];

        $messages = [
            'scheduled_time.required' => 'Por Favor seleccione un hora valida para su cita.'
        ];
       /*  $this->validate($request, $rules, $messages); */

       $validator = Validator::make($request->all(),$rules, $messages);

        $validator->after(function ($validator) use ($request, $scheduleService) {
            $date = $request->input('schedued_date');
            $doctorId = $request->input('doctor_id');
            $scheduled_time= $request->input('scheduled_time');
            if($date && $doctorId && $scheduled_time)
            {
                $start = new Carbon($scheduled_time);
            }
            else
            {
                return;
            }
            if (!$scheduleService->isAvailableInterval($date, $doctorId, $start)) {
                $validator->errors()->add(
                    'available_time', 'La hora seleccionada ya se encuentra reservada por otro paciente'
                );
            }
        });

       if($validator->fails())
       {
           return back()
            ->withErrors($validator)
            ->withInput();
       }

        $data = $request->only([
            'description',
            'specialty_id',
            'doctor_id',
            'patient_id',
            'scheduled_date',
            'scheduled_time',
            'type'
        ]);
        $data['patient_id']= auth()->id();
        //darformato para la hora
        $carbonTime = Carbon::createFromFormat('g:i A', $data['scheduled_time']);
        $data['scheduled_time'] = $carbonTime->format('H:i:s');
        Appointment::create($data);

        $notification = 'La cita se ha registrado correctamente!';
        return back()->with(compact('notification'));
    }

    public function postCancel(Appointment $appointment, Request $request)
    {

        if($request->has('justification'))
        {
            $cancellation = new CancelledAppointment();
            $cancellation->justification = $request->input('justification');
            $cancellation->canceled_by_id = auth()->id();

            //4cancelation->appointment_id =

            $appointment->cancellation()->save($cancellation);
        }

        $appointment->status = 'Cancelada';
        $appointment->save();

        $notification = 'La cita se ha cancelado correctamente.';
        return redirect('/citas')->with(compact('notification'));
    }

    public function showCancel(Appointment $appointment)
    {
        if($appointment->status == 'Confirmada')
        {
            return view('citas.cancelar', compact('appointment'));
        }
        return view('citas.index', compact('appointment'));
    }
    
}
