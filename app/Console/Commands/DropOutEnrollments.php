<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Submission;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Stopwatch\Stopwatch;

class DropOutEnrollments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'enrollments:dropout';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dropout enrollments on specified date.';

    public function __construct(
        private readonly Stopwatch $stopwatch,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            DB::beginTransaction();

            $deadline = Carbon::parse(Enrollment::latest('id')->value('deadline_at'));

            $this->stopwatch->start(__CLASS__);

            $this->dropOutEnrollmentsBefore($deadline);

            $this->stopwatch->stop(__CLASS__);
            $this->info($this->stopwatch->getEvent(__CLASS__));

            DB::rollBack();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * The dropout process should fulfil the following requirements:
     * 1. The enrollment deadline has passed.
     * 2. The student has no active exam.
     * 3. The student has no submission waiting for review.
     * 4. Update the enrollment status to `DROPOUT`.
     * 5. Create an activity log for the student.
     */
    private function dropOutEnrollmentsBefore(Carbon $deadline)
    {
        // batch process configure
        $batchSize = 5000;

        // get  total enroll 
        $enrollmentsToBeDroppedOut = Enrollment::select('id', 'student_id', 'course_id')->where('deadline_at', '<=', $deadline);

        $totalEnrollmentsToBeDroppedOut =  $enrollmentsToBeDroppedOut->count();
        $this->info('Enrollments to be dropped out: ' . $totalEnrollmentsToBeDroppedOut);

        $droppedOutEnrollments = 0;

        // batch process using chunk per batchSize
        // prevent save big data in one hit
        $enrollmentsToBeDroppedOut->chunk($batchSize, function ($enrollments) use (&$droppedOutEnrollments) {
            $studentIds = $enrollments->pluck('student_id')->toArray();
            $courseIds = $enrollments->pluck('course_id')->toArray();

            $enrollmentStudentMap = $enrollments->pluck('student_id', 'id')->toArray();

            // get exam inProgress and submission waitingReview by studentIds and courseIds
            $studentsWithExams = Exam::whereIn('course_id', $courseIds)
                ->whereIn('student_id', $studentIds)
                ->where('status', 'IN_PROGRESS')
                ->pluck('student_id')
                ->toArray();

            // Get students with pending submissions
            $studentsWithSubmissions = Submission::whereIn('course_id', $courseIds)
                ->whereIn('student_id', $studentIds)
                ->where('status', 'WAITING_REVIEW')
                ->pluck('student_id')
                ->toArray();

            $studentsToExclude = array_merge($studentsWithExams, $studentsWithSubmissions);

            // Filter enrollments that should be dropped out
            $dropoutIds = $enrollments->whereNotIn('student_id', $studentsToExclude)->pluck('id')->toArray();

            if (!empty($dropoutIds)) {
                // Update enrollments to 'DROPOUT'
                Enrollment::whereIn('id', $dropoutIds)->update([
                    'status' => 'DROPOUT',
                    'updated_at' => now(),
                ]);

                // variable for saving batch activity
                $activityLogs = [];
                foreach ($dropoutIds as $id) {
                    $activityLogs[] = [
                        'resource_id' => $id,
                        'user_id' => $enrollmentStudentMap[$id],
                        'description' => 'COURSE_DROPOUT',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                Activity::insert($activityLogs);
                $droppedOutEnrollments += count($dropoutIds);
            }
        });

        $this->info('Excluded from drop out: ' . $totalEnrollmentsToBeDroppedOut - $droppedOutEnrollments);
        $this->info("Final dropped out enrollments: {$droppedOutEnrollments}");
    }
}
