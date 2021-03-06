<?php

namespace LibreEHR\FHIR\Adapters;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use LibreEHR\Core\Contracts\PatientAdapterInterface;
use LibreEHR\Core\Contracts\PatientInterface;
use LibreEHR\Core\Contracts\DocumentInterface;

use LibreEHR\Core\Contracts\BaseAdapterInterface;
use LibreEHR\Core\Contracts\PatientRepositoryInterface;
use LibreEHR\Core\Emr\Criteria\ByPid;
use LibreEHR\Core\Emr\Criteria\PatientByPid;
use LibreEHR\Core\Emr\Repositories\PharmacyRepository;
use LibreEHR\Core\Emr\Repositories\ProviderRepository;
use LibreEHR\FHIR\Http\Controllers\Auth\AuthModel\User;
use LibreEHR\FHIR\Utilities\UUIDClass;
use PHPFHIRGenerated\FHIRDomainResource\FHIRPatient;
use PHPFHIRGenerated\FHIRElement\FHIRCode;
use \PHPFHIRGenerated\FHIRElement\FHIRAttachment;
use PHPFHIRGenerated\FHIRElement\FHIRContactPoint;
use PHPFHIRGenerated\FHIRElement\FHIRContactPointSystem;
use PHPFHIRGenerated\FHIRElement\FHIRContactPointUse;
use PHPFHIRGenerated\FHIRElement\FHIRDate;
use PHPFHIRGenerated\FHIRElement\FHIRId;
use PHPFHIRGenerated\FHIRElement\FHIRIdentifier;
use PHPFHIRGenerated\FHIRElement\FHIRIdentifierUse;
use PHPFHIRGenerated\FHIRElement\FHIRInstant;
use PHPFHIRGenerated\FHIRElement\FHIRMeta;
use PHPFHIRGenerated\FHIRElement\FHIRNameUse;
use PHPFHIRGenerated\FHIRElement\FHIRHumanName;
use PHPFHIRGenerated\FHIRElement\FHIRString;
use PHPFHIRGenerated\FHIRElement\FHIRUnsignedInt;
use PHPFHIRGenerated\FHIRElement\FHIRUri;
use PHPFHIRGenerated\FHIRResource\FHIRBundle;
use PHPFHIRGenerated\FHIRResourceContainer;
use PHPFHIRGenerated\PHPFHIRResponseParser;
use PHPFHIRGenerated\FHIRElement\FHIRExtension;
use ArrayAccess;

class FHIRPatientAdapter extends AbstractFHIRAdapter implements BaseAdapterInterface, PatientAdapterInterface
{
    /**
     * @param $id ID identifying resource
     * @return string
     *
     * Takes a resource ID and returns a FHIR JSON or XML string
     * in response
     */
    public function retrieve($id)
    {
        $this->repository->finder()->pushCriteria(new ByPid($id));
        $patientInterface = $this->repository->find();
        return $this->interfaceToModel($patientInterface);
    }

    /**
     * @param Request $request
     * @return FHIRPatient
     */
    public function store(Request $request)
    {
        // TODO add validation
        $data = $request->getContent();
        $interface = $this->jsonToInterface($data);
        $storedInterface = $this->storeInterface($interface);
        return $this->interfaceToModel($storedInterface);
    }

    public function storeMaster(Request $request)
    {
        $data = $request->getContent();
        $interface = $this->jsonToInterface($data);
        $storedInterface = $this->storeMasterInterface($interface);
        return $this->interfaceToModel($storedInterface);
    }

    /**
     * @param PatientInterface $patientInterface
     * @return PatientInterface
     */
    public function storeInterface(PatientInterface $patientInterface)
    {
        // Store an interface of a dependent patient using the logged-in
        // user's database conneciton
        $user = Auth::user();
        if ( $user->connection &&
            $user->ehr_pid ) {
            $this->repository->setConnection( $user->connection );
            $patientInterface->setStatus(User::STATUS_ACTIVE);
            if ( $patientInterface->getId() ) {
                $this->repository->update( $patientInterface );
            } else {
                $this->repository->create( $patientInterface );
            }
        }

        return $patientInterface;
    }

    /**
     * @param PatientInterface $patientInterface
     * @return PatientInterface
     */
    public function storeMasterInterface(PatientInterface $patientInterface)
    {
        // Before we store the patient:
        // 1. figure out which connection to use
        // 2. get the emr_provider ID from provider table
        // 3. get the emr_pharmacy ID from the pharmacy table

        // TODO Throw exception when not a valid pharmacy or provider
        $pharmacyId = $patientInterface->getPharmacyId();
        $pharmRepo = new PharmacyRepository();
        $pharmacy = $pharmRepo->get($pharmacyId);
        $emrId = $pharmacy->getEmrId();
        $patientInterface->setPharmacyId($emrId);

        $providerId = $patientInterface->getProviderId();
        $providerRepo = new ProviderRepository();
        $provider = $providerRepo->get($providerId);
        $emrId = $provider->getEmrId();
        $patientInterface->setProviderId($emrId);

        $connection = $provider->getConnectionKey();
        $this->repository->setConnection($connection);

        $user = Auth::user();
        if ($user->connection == $connection &&
            $user->ehr_pid
        ) {
            // We already have a patient link in the EHR database using this connection
            $patientInterface = $this->repository->update($patientInterface);
        } else {
            // This is a registration because we don't have an EHR ID
            $patientInterface->setStatus(User::STATUS_PENDING);
            $patientInterface = $this->repository->create($patientInterface);
        }

        $patientInterface->setGroupId( $patientInterface->getPid() );
        $patientInterface->save();

        // Need to set the EHR ID and connection in the user's data
        $user->connection = $connection;
        $user->ehr_pid = $patientInterface->getPid();
        $user->status = $patientInterface->getStatus();
        $user->save();
        Auth::setUser($user);

        return $patientInterface;
    }

    /**
     * @param ArrayAccess $collection
     * @return array
     */
    public function collectionToOutput(Request $request = null)
    {
        if (!empty($request->server->get('QUERY_STRING'))) {
            $data = $this->parseUrl($request->server->get('QUERY_STRING'));
            $collection = $this->repository->getPatientsByParam($data);
        } else {
            $collection = $this->repository->fetchAll();
        }

        $bundle = $this->buildBundle($collection);

        return $bundle;
    }

    /**
     * @param $groupId
     * @return FHIRBundle
     *
     * Return a bundle of all patients in my group
     */
    public function showGroup( $groupId )
    {
        $collection = $this->repository->getPatientsByParam( ['groupId' => $groupId ] );
        return $this->buildBundle( $collection );
    }

    protected function buildBundle( $collection )
    {
        $bundle = new FHIRBundle();
        $bundleId = UUIDClass::v4();
        $currentDate = date('Y-m-d H:i:s');
        $count = 0;
        foreach ( $collection as $patient ) {
            if ( $patient instanceof PatientInterface ) {
                $fhirPatient = $this->interfaceToModel( $patient );
                $resourceContainer = new FHIRResourceContainer();
                $resourceContainer->setPatient($fhirPatient);
                $bundleEntry = new FHIRBundle\FHIRBundleEntry();
                $fullUrl = new FHIRUri();
                $patientUrl = $_SERVER['HTTP_HOST'] . '/' . $bundleId;
                $fullUrl->setValue($patientUrl);
                $bundleEntry->setFullUrl($fullUrl);
                $bundleEntry->setResource($resourceContainer);
                $response = new FHIRBundle\FHIRBundleResponse();
                $location = new FHIRUri();
                $location->setValue('Patient/'.$patient->getId().'/_history');
                $response->setLocation($location);
                $lastModified = new FHIRInstant();
                $lastModified->setValue($currentDate);
                $response->setLastModified($lastModified);
                $bundleEntry->setResponse($response);
                $bundle->addEntry($bundleEntry);

                $count++;
            }
        }

        $meta = new FHIRMeta();
        $lastUpdated = new FHIRInstant();
        $lastUpdated->setValue($currentDate);
        $meta->setLastUpdated($lastUpdated);
        $bundle->setMeta($meta);

        $id = new FHIRId();
        $id->setValue($bundleId);
        $bundle->setId($id);

        $link = new FHIRBundle\FHIRBundleLink();
        $relation = new FHIRString();
        $relation->setValue('self');
        $link->relation = $relation;
        $fullUrl = $_SERVER['HTTP_HOST'];
        $url = new FHIRUri;
        $url->setValue($fullUrl);
        $link->url = $url;
        $bundle->addLink($link);

        $total = new FHIRUnsignedInt();
        $total->setValue($count);
        $bundle->total = $total;

        $type = new FHIRCode();
        $type->setValue('searchset');
        $bundle->type = $type;


        return $bundle;
    }

    /**
     * @param $id
     * @return array
     */
    public function showPatient($id)
    {
        $patient = $this->repository->get($id);
        if (!empty($patient)) {
            return  $this->interfaceToModel( $patient );
        } else {
            abort(404, 'No Patient with id = ' . $id. ' found');
        }
    }

    public function update($request)
    {
        $data = $request->json()->all();

        if(!isset($data['id'])) {
            return json_encode(array('error' => 'no arguments'));
        }
        // TODO add validation
        $storedInterface = $this->requestToInterface( $data['id'], $data );

        return $this->interfaceToModel( $storedInterface );

    }

    /**
     * @param string $data
     * @return AppointmentInterface
     *
     * Takes a FHIR post string and returns a AppointmentInterface
     */
    public function requestToInterface( $id, $data )
    {

        $patientInterface = $this->repository->update($id, $data);

        return $patientInterface;
    }

    /**
     * @param PatientInterface $patientInterface
     * @return PatientInterface
     */
    public function updateInterface( PatientInterface $patientInterface )
    {
        $patientInterface = $this->repository->update( $patientInterface );
        return $patientInterface;
    }






    /**
     * @param $id
     * @return array
     */
    public function removePatient($id)
    {
        return $this->repository->delete($id);
    }


    /**
     * @param string $data
     * @return PatientInterface
     *
     * Takes a FHIR post string and returns a PatientInterface
     */
    public function jsonToInterface( $data )
    {
        $parser = new \PHPFHIRGenerated\PHPFHIRResponseParser();
        $fhirPatient = $parser->parse( $data );
        if ( $fhirPatient instanceof FHIRPatient ) {
            return $this->modelToInterface( $fhirPatient );
        } else {
            // Error, the Resource does not match, expecting a Patient,
            // // but got something else.
            abort(403, 'Error, the Resource does not match, expecting a Patient, but got "' . typeOf($fhirPatient). '"');
        }

    }

    public function modelToInterface( FHIRPatient $fhirPatient )
    {
        $patientInterface = App::make('LibreEHR\Core\Contracts\PatientInterface');
        if ($patientInterface instanceof PatientInterface) {
            $birthDate = $fhirPatient->getBirthDate()->getValue();
            $patientInterface->setDOB($birthDate);
            $humanName = $fhirPatient->getName();
            $familyName = $humanName[0]->getFamily();
            $lname = $familyName[0]->getValue();
            $patientInterface->setLastName($lname);
            $givenName = $humanName[0]->getGiven();
            $fname = $givenName[0]->getValue();
            $patientInterface->setFirstName($fname);
            $gender = $fhirPatient->getGender();
            $patientInterface->setGender($gender->getValue());

            $phoneNumbers = $fhirPatient->getTelecom();
            $primaryPhone = $phoneNumbers[0]->getValue();
            $patientInterface->setPrimaryPhone($primaryPhone);

            $extensions = $fhirPatient->getExtension();
            foreach ($extensions as $extension) {
                $url = $extension->getUrl();
                if ( strpos( $url, "/extension/contracts" ) !== false ) {
                    $x2s = $extension->getExtension();
                    foreach ($x2s as $x2) {
                        $url2 = $x2->getUrl();
                        switch ($url2) {
                            case "#terms-of-service":
                                break;
                            case "#allow-sms" :
                                $allowSms = $x2->getValueBoolean();
                                $allowSms = ($allowSms->getValue() == 1) ? 'YES' : 'NO';
                                $patientInterface->setAllowSms($allowSms);
                                break;
                        }
                    }
                } else if ( strpos( $url, "/extension/gponline-patient-data" ) !== false ) {

                        $x2s = $extension->getExtension();
                        foreach ($x2s as $x2) {
                            $url2 = $x2->getUrl();
                            switch ($url2) {
                                case "#providerId":
                                    $providerId = $x2->getValueString();
                                    $patientInterface->setProviderId( $providerId->getValue() );
                                    break;
                                case "#pharmacyId" :
                                    $pharmacyId = $x2->getValueString();
                                    $patientInterface->setPharmacyId( $pharmacyId->getValue() );
                                    break;
                                case "#groupId" :
                                    $groupId = $x2->getValueString();
                                    $patientInterface->setGroupId( $groupId->getValue() );
                                    break;
                                case "#status" :
                                    $status = $x2->getValueString();
                                    $patientInterface->setStatus( $status->getValue() );
                                    break;
                            }
                        }
                }
            }

            $photos = $fhirPatient->getPhoto();
            if(!empty($photos)) {
                $photo = $photos[0];
                $formatCode = $photo->getContentType();
                $mimetype = $formatCode->getValue();
                $ext = "";
                switch ($mimetype) {
                    case "image/jpeg":
                        $ext = "jpg";
                        break;
                    default:
                        $ext = "jpeg";
                        break;
                }
                $base64Binary = $photo->getData();
                $photo = App::make('LibreEHR\Core\Contracts\DocumentInterface');
                $photo->setMimetype($mimetype);
                $photo->base64Data = $base64Binary->getValue();
                $photo->filename = rand() . "." . $ext;
                $patientInterface->setPhoto($photo);
            }
        }

        return $patientInterface;
    }

    /**
     * @param PatientInterface $patient
     * @return FHIRPatient
     */
    public function interfaceToModel( PatientInterface $patient )
    {
        $fhirPatient = new FHIRPatient();

        $identifier = new FHIRIdentifier();
        $use = new FHIRIdentifierUse();
        $use->setValue( "usual" );
        $identifier->setUse( $use );
        $value = new FHIRString();
        $value->setValue( $patient->getId() );
        $identifier->setValue( $value );
        $fhirPatient->addIdentifier( $identifier );

        $dob = new FHIRDate();
        $dob->setValue( $patient->getDOB() );
        $fhirPatient->setBirthDate( $dob );

        $name = new FHIRHumanName();
        $nameUse = new FHIRNameUse();
        $nameUse->setValue( "usual" );
        $name->setUse( $nameUse );
        $givenName = new FHIRString();
        $name->addGiven( $givenName->setValue( $patient->getFirstName() ) );
        $familyName = new FHIRString();
        $name->addFamily( $familyName->setValue( $patient->getLastName() ) );
        $fhirPatient->addName( $name );

        $gender = new FHIRCode();
        $gender->setValue( $patient->getGender() );
        $fhirPatient->setGender( $gender );

        $phone = new FHIRContactPoint();
        $use = new FHIRContactPointUse();
        $use->setValue( 'primary' );
        $phone->setUse( $use );
        $system = new FHIRContactPointSystem();
        $system->setValue( 'phone' );
        $phone->setSystem( $system );
        $phoneNumber = new FHIRString();
        $phoneNumber->setValue( $patient->getPrimaryPhone() );
        $phone->setValue( $phoneNumber );
        $fhirPatient->addTelecom( $phone );

        $email = new FHIRContactPoint();
        $use = new FHIRContactPointUse();
        $use->setValue( 'primary' );
        $email->setUse( $use );
        $system = new FHIRContactPointSystem();
        $system->setValue( 'email' );
        $email->setSystem( $system );
        $emailAddress = new FHIRString();
        $emailAddress->setValue( $patient->getEmailAddress() );
        $email->setValue( $emailAddress );
        $fhirPatient->addTelecom( $email );


        if ( $patient->getPhoto() ) {
            $photo = new FHIRAttachment();
            $contentType = new FHIRCode();
            $contentType->setValue( $patient->getPhoto()->getMimetype() );
            $photo->setContentType( $contentType );
            $photoUrl = new FHIRUri();
            $photoUrl->setValue( $patient->getPhoto()->getPublicUrl() );
            $photo->setUrl( $photoUrl );
            $fhirPatient->addPhoto( $photo );
        }

        $extension = new FHIRExtension;
        $extension1 = new FHIRExtension;
        $extension2 = new FHIRExtension;
        $extension3 = new FHIRExtension;
        $extension4 = new FHIRExtension;
        $extension5 = new FHIRExtension;

        $extension->setUrl( \URL::to('/fhir') . "/extension/gponline-patient-data" );

        $extension1->setUrl('#groupId');
        $value = new FHIRString();
        $value->setValue($patient->getGroupId());
        $extension1->setValueString($value);

        $extension2->setUrl('#status');
        $value = new FHIRString();
        $value->setValue($patient->getStatus());
        $extension2->setValueString($value);

        $extension3->setUrl('#providerId');
        $value = new FHIRString();
        $value->setValue($patient->getProviderId());
        $extension3->setValueString($value);

        $extension4->setUrl('#pharmacyId');
        $value = new FHIRString();
        $value->setValue($patient->getPharmacyId());
        $extension4->setValueString($value);

        $extension5->setUrl('#stripeToken');
        $value = new FHIRString();
        $value->setValue("Some value of stripeToken");
        $extension5->setValueString($value);

        $extension->addExtension($extension1);
        $extension->addExtension($extension2);
        $extension->addExtension($extension3);
        $extension->addExtension($extension4);
        $extension->addExtension($extension5);

        $fhirPatient->addExtension($extension);

        return $fhirPatient;
    }
}