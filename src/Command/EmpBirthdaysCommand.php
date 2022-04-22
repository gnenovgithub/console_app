<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;

class EmpBirthdaysCommand extends Command
{
    /*
     * Example: symfony console app:import-emp-birthdays emp_list.txt --office england-and-wales
     */
    protected static $defaultName = 'app:import-emp-birthdays';

    private $off_days = ['2022-12-26', '2022-12-27', '2022-01-03'];
    private $cake_days = [];
    private $output;

    /*
     * @param str $office
     */
    private function set_off_days( $office )
    {
        $ch = curl_init('https://www.gov.uk/bank-holidays.json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        $response = curl_exec($ch);
        curl_close($ch);
        $table_data = array();
        if( !empty($response) )
        {
            $data = json_decode($response);
            $off_days_list = array('New Year’s Day', 'Boxing Day', 'Christmas Day' );
            foreach ( $data as $d )
            {
                if( $d->division == $office )
                {
                    foreach ( $d->events as $event )
                    {
                        if( in_array($event->title, $off_days_list) )
                        {
                            $this->off_days[] = $event->date;
                            $table_data[] = [$event->date];
                        }
                    }
                }
            }

        }
        $table = new Table($this->output);
        $table->setHeaders(['Office closed days for '.$office])->setRows($table_data);
        $table->setColumnWidths([35]);
        $table->render();
    }

    private function day_after_birthday( $date, $birthday_date = false ):string
    {
        if( $birthday_date || in_array($date, $this->off_days )
            ||  date('w', strtotime($date)) == '6' || date('w', strtotime($date)) == '0' )
        {
            $date = $this->day_after_birthday( date('Y-m-d', strtotime($date. ' + 1 days')) );
        }
        $this->cake_days[$date] = 1;
        //check for 3-th in a row
        if( isset($this->cake_days[date('Y-m-d', strtotime($date. ' - 1 days'))])
            && isset($this->cake_days[date('Y-m-d', strtotime($date. ' - 2 days'))]) )
        {
            $date = $this->day_after_birthday( date('Y-m-d', strtotime($date. ' + 1 days')) );
            unset($this->cake_days[$date]);
            $this->off_days[] = $date;
        }

        return $date;
    }

    private function outputCSV( $cakes_data )
    {
        $csv_file_name = 'cakes_list.csv';
        $names = $cakes_counter = array();
        foreach( $cakes_data as $data )
        {
            $date = $data['day_after_birthday'];
            if( isset($cakes_counter[$date]) )
            {
                $cakes_counter[$date] += 1;
                $names[$date] = $names[$date] . ', ' . $data['full_name'];
            }
            else
            {
                $cakes_counter[$date] = 1;
                $names[$date] = $data['full_name'];
            }

            $yesterday = date('Y-m-d', strtotime($date. ' - 1 days'));
            if( isset( $cakes_counter[$yesterday] ) )
            {
                $cakes_counter[$date] = $cakes_counter[$date] + $cakes_counter[$yesterday];
                unset( $cakes_counter[$yesterday] );
                $names[$date] = $names[$date] . ', ' . $names[$yesterday];
                unset( $names[$yesterday] );
            }
        }

        $file = fopen($csv_file_name,"w");
        $this->output->writeln('<info>CSV file successfully created - '.$csv_file_name.'</info>');
        fputcsv($file, ['Date', 'Number of Small Cakes', 'Number of Large Cakes', 'Names of people getting cake']);
        foreach( $cakes_counter as $date => $counter )
        {
            $smallCakes = $largeCakes = 0;
            if( $counter == 1 )
            {
                $smallCakes = 1;
            }
            else
            {
                $largeCakes = 1;
            }
            fputcsv($file, [ $date, $smallCakes, $largeCakes, $names[$date] ]);
        }
        fclose($file);
        //return $csv_file_name;
    }

    protected function emp_list_form_file( $file_name )
    {
        $file = fopen($file_name, "r") or die("Unable to open file!");
        $file_lines = preg_split("/\r\n|\n|\r/", fread($file,filesize($file_name)));
        fclose($file);
        $data = array();
        $invalid_data_rows = 0;
        foreach( $file_lines as $file_line )
        {
            if( !empty($file_line) )
            {
                $l = explode(',' , $file_line );
                if ( isset($l[1]) )
                {
                    //validate date
                    $d = explode('-' , $l[1] );
                    if( isset($d[2]) && checkdate( $d[1], $d[2], $d[0]) )
                    {
                        $data[] = array(
                            'full_name'     => $l[0],
                            'birthday_date' => checkdate( $d[1], $d[2], date('Y')) ? date('Y-'.$d[1].'-'.$d[2]) : date('Y-03-01')
                        );
                    }
                    else
                    {
                        $invalid_data_rows += 1;
                    }

                }
                else
                {
                    $invalid_data_rows += 1;
                }

            }
        }

        if( $invalid_data_rows )
        {
            $this->output->writeln('<error>Invalid lines find in the file: '.$invalid_data_rows.'</error>');
        }
        else
        {
            $this->output->writeln('<info>All data is loaded from the file! Loaded lines: '.count($data).'</info>');
        }

        usort($data, function($a, $b) {
            return $a['birthday_date'] <=> $b['birthday_date'];
        });

        $emp_data = array();
        foreach( $data as $birthday_date )
        {
            $day_after_birthday = $this->day_after_birthday( $birthday_date['birthday_date'], true );
            $emp_data[] = array(
                'full_name'             => $birthday_date['full_name'],
                'birthday_date'         => $birthday_date['birthday_date'],
                'day_after_birthday'    => $day_after_birthday
            );
        }

        if( !empty($emp_data) )
        {
            $this->outputCSV( $emp_data );
        }
        else
        {
            $this->output->writeln('<error>Please make sure that the data in the file is formatted correctly!</error>');
            $this->output->writeln([
                '',
                'Example: Steve,1992-10-14',
                ''
            ]);
        }


        return true;
    }


    protected function configure(): void
    {
        $this->addArgument('filename', InputArgument::REQUIRED, 'The name of the file to import')
            ->addOption(
                'office',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Where is the office located?',
                ['england-and-wales', 'scotland', 'northern-ireland']
            );
    }

// ...
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $fileName = $input->getArgument('filename');
        $fileInfo = pathinfo($fileName);
        if( !isset($fileInfo['extension']) || $fileInfo['extension'] != 'txt' )
        {
            $output->writeln('<error>Please use txt file!</error>');
            return Command::FAILURE;
        }
        if ( file_exists($fileName) )
        {
            $office = current($input->getOption('office'));
            $this->set_off_days( $office );
            $output->writeln([
                '',
                '=============================================',
                'Getting data from file: ' . $fileName,
                '=============================================',
                '',
            ]);
            $this->emp_list_form_file( $fileName );

            return Command::SUCCESS;
        }
        else
        {
            $output->writeln('<error>We can`t identify the file with name: '.$fileName .'</error>');
        }
        return Command::FAILURE;

    }

}