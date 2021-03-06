<?php
namespace Snapshoter\Command;

use Snapshoter\Aws\AwsClientFactory;
use Snapshoter\Exception\SnapshotUnavailableException;
use Snapshoter\Sorter\SnapshotResultSorter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AttachCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('attach')
            ->setDescription('Attach a EBS volume to an ec2 instance from a given tagged snapshot')
            ->addOption(
                'device_key',
                'm',
                InputOption::VALUE_REQUIRED,
                'The desired mount point as defined by aws.',
                '/dev/sdf'
            )
            ->addOption(
                'device_value',
                null,
                InputOption::VALUE_REQUIRED,
                'The desired mount point as defined by your os.  For example ubuntu turns /dev/sdf to /dev/xvdf. default: /dev/xvdf',
                '/dev/xvdf'
            )
            ->addOption(
                'volume_size',
                's',
                InputOption::VALUE_REQUIRED,
                'The desired initial volume size by default it will be snapshot size'
            )
            ->addOption(
                'availability_zone',
                'z',
                InputOption::VALUE_REQUIRED,
                "The availability_zone in which to create the volume",
                'eu-west-1b'
            )
            ->addOption(
                'instance_id',
                null,
                InputOption::VALUE_REQUIRED,
                'The instance id to attach the new volume, if not defined it will try to do it in the current machine'
            )
            ->addOption(
                'delete_on_termination',
                null,
                InputOption::VALUE_NONE,
                'The attached volume will be deleted on instance termination'
            )
            ->addArgument('snapshot_tag', InputArgument::REQUIRED, "the snapshot tag you're looking for");
    }

    /**
     * @param $snapshotTag
     * @return mixed
     * @throws SnapshotUnavailableException
     */
    private function getMostRecentSnapshot($snapshotTag)
    {
        $returnObj = $this->getContainer()->get('snapshoter.aws.ec2.client')->describeSnapshots(
            array(
                'OwnerIds' => array('self'),
                'Filters' => array(
                    array('Name' => 'tag:Name', 'Values' => array($snapshotTag))
                )
            )
        );

        $snapshotsList = SnapshotResultSorter::sort($returnObj['Snapshots']);

        if (count($snapshotsList) > 0) {
            return end($snapshotsList);
        } else {
            throw new SnapshotUnavailableException();
        }
    }


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $snapshotTag = $input->getArgument('snapshot_tag');
        list($instanceId, $localInstance) = $this->getInstanceId($input);
        $availabilityZone = $input->getOption('availability_zone');
        $deviceKey = $input->getOption('device_key');
        $deviceValue = $input->getOption('device_value');
        $deleteOnTermination = $input->hasOption('delete_on_termination');
        $desiredVolumeSize = $input->getOption('volume_size');

        $snapshotData = $this->getMostRecentSnapshot($snapshotTag);

        $output->writeln('Mounting snapshot: '.$snapshotData['SnapshotId']);

        $volumeSize = $this->getVolumeSize($desiredVolumeSize, $snapshotData);

        $volumeId = $this->createVolume($volumeSize, $snapshotData, $availabilityZone);

        $output->writeln('Volume created:'.$volumeId);

        $this->attachVolume($volumeId, $instanceId, $deviceKey, $localInstance, $deviceValue);

        $output->writeln('Volume attached:'.$volumeId.' in '.$deviceValue);

        if ($deleteOnTermination) {
            $this->getContainer()->get('snapshoter.aws.ec2.client')->modifyInstanceAttribute(
                array(
                    'Attribute' => 'blockDeviceMapping',
                    'BlockDeviceMappings' => array(
                        array(
                            'DeviceName' => $deviceKey,
                            'Ebs' => array(
                                'DeleteOnTermination' => true,
                                'VolumeId' => $volumeId
                            )
                        )
                    ),
                    'InstanceId' => $instanceId
                )
            );

            $output->writeln('Volume Marked as delete on termination');
        }
    }

    /**
     * @param OutputInterface $output
     * @param                 $volumeSize
     * @param                 $snapshotData
     * @param                 $availabilityZone
     * @return string
     */
    protected function createVolume($volumeSize, $snapshotData, $availabilityZone)
    {
        $volume = $this->getContainer()->get('snapshoter.aws.ec2.client')->createVolume(
            array(
                'Size' => $volumeSize,
                'SnapshotId' => $snapshotData['SnapshotId'],
                'AvailabilityZone' => $availabilityZone,
            )
        );

        $volumeId = $volume['VolumeId'];

        $this->getContainer()->get('snapshoter.waiter.volume_available')->wait(array('VolumeId' => $volumeId), 10, 3);

        return $volumeId;
    }

    /**
     * @param $desiredVolumeSize
     * @param $snapshotData
     * @return mixed
     */
    private function getVolumeSize($desiredVolumeSize, $snapshotData)
    {
        if ($desiredVolumeSize == null || $desiredVolumeSize < $snapshotData['VolumeSize']) {
            $volumeSize = $snapshotData['VolumeSize'];

            return $volumeSize;
        } else {
            $volumeSize = $desiredVolumeSize;

            return $volumeSize;
        }
    }

    /**
     * @param InputInterface $input
     * @return array
     */
    private function getInstanceId(InputInterface $input)
    {
        if (!$input->hasOption('instance_id') || empty($input->getOption('instance_id'))) {
            $instanceId = $this->getContainer()->get('snapshoter.aws.metadata.client')->getInstanceId();
            $localInstance = true;

            return array($instanceId, $localInstance);
        } else {
            $instanceId = $input->getOption('instance_id');
            $localInstance = false;

            return array($instanceId, $localInstance);
        }
    }

    /**
     * @param $volumeId
     * @param $instanceId
     * @param $deviceKey
     * @param $localInstance
     * @param $deviceValue
     */
    private function attachVolume($volumeId, $instanceId, $deviceKey, $localInstance, $deviceValue)
    {
        $this->getContainer()->get('snapshoter.aws.ec2.client')->attachVolume(
            array(
                'VolumeId' => $volumeId,
                'InstanceId' => $instanceId,
                'Device' => $deviceKey
            )
        );

        $this->getContainer()->get('snapshoter.waiter.volume_in_use')->wait(array('VolumeId' => $volumeId), 10, 3);

        if ($localInstance) {
            $this->getContainer()->get('snapshoter.waiter.fstab')->wait(array('device' => $deviceValue), 10, 3);
        }
    }
}