<?php

namespace App\Http\Controllers;
use App\Http\ApiRequestContext;
use Ez\Data\BusinessRepo;
use Ez\Data\TaxRepo;
use Illuminate\Http\Request;
use App\Rules\OnlyPostiveNumber;
use App\Rules\NoSpecialCharacters;
use Ez\Data\ProductRepo;
use Ez\Data\EzadApiRepo,Ez\Data\DepartmentRepo;
use Ez\Data\SearchHistoryRepo;
use Illuminate\Support\Arr;
use Ez\Data\UtilityRepo;
use Illuminate\Validation\Rule;
use Ez\Data\EsProductRepo;
use Ez\Data\EsDepartmentRepo;
use App\Models\Department;

class ProductController extends Controller{
    /**
     * @var UtilityRepo
     */
    public $utilityRepo;

    /**
     * @var EzadApiRepo
     */
    protected $curlRepository;
    protected $repository;
    protected $esRepo;
    protected $departmentRepository;
    protected $esDepartmentRepo;
    protected $searchHistoryRepository;
    protected $businessRepo;
    protected $dept_level = 3;
    protected $es;


    public function __construct(ProductRepo $ProductRepo,
                                EzadApiRepo $EzadApiRepo,
                                DepartmentRepo $DepartmentRepo,
                                UtilityRepo $UtilityRepo,
                                SearchHistoryRepo $SearchHistoryRepo,
                                EsProductRepo $EsProductRepo,
                                EsDepartmentRepo $EsDepartmentRepo,
                                BusinessRepo $businessRepo)
    {
        $this->repository               =   $ProductRepo;
        $this->curlRepository           =   $EzadApiRepo;
        $this->departmentRepository     =   $DepartmentRepo;
        $this->utilityRepo              =   $UtilityRepo;
        $this->searchHistoryRepository  =   $SearchHistoryRepo;
        $this->esRepo                   =   $EsProductRepo;
        $this->esDepartmentRepo         =   $EsDepartmentRepo;
        $this->businessRepo = $businessRepo;
    }

    /* SEARCH PRODUCTS AND DEPARTMENTS HIERARCHY VISE*/
    public function doDepartmentAndProductSuggestions(Request $request){

        $businessId = ApiRequestContext::$businessId;
        $customer       =   [];
        $validatedData  =   $this->validate($request,[
            'search'        =>  ['filled'],
            'limit'         =>  ['filled',new OnlyPostiveNumber]
        ]);

        // Get stored search history
        $request->request->add(['business_id' => $businessId]);

        $response   =   UtilityRepo::makeResponse(true,'Departments And Products');
        if(config('services.search.enabled')){
            $response['data']['products']   =   $this->esRepo->getSearchSuggestions($request);
            $response['data']['departments']=   $this->esDepartmentRepo->getSearchSuggestions($request);
        }else{
            $response['data']   =   $this->repository->getProductAndDepartmentSuggestions($request);
        }
        //$response['data']['history']=   $this->searchHistoryRepository->getSearchHistory($validatedData);

        return response()->json($response,200);
    }

    /* CLEAR SEARCH HISTORY OF ANY SPECIFIC CUSTOMER OR VISITOR(DEVICE) */
    public function doClearSearchHistory(Request $request){

        $businessId     =   ApiRequestContext::$businessId;
        $customer       =   [];
        $validatedData  =   $this->validate($request,[
            'customer_slug' =>  ['required_without:device_id','string','size:16',new NoSpecialCharacters,
                                    function($attribute,$value,$fail) use (&$customer){
                                        $customer = $this->utilityRepo->checkCustomerExists($value);
                                        if(empty($customer)){
                                            $fail('The given :attribute is invalid.');
                                            return false;
                                        }
                                    }
                                ],
            'device_id'     =>  'required_without:customer_slug'
        ]);

        if(!empty($customer))
            $validatedData['customer_id']   =   $customer['id'];
        $validatedData['business_id']       =   $businessId;
        $this->searchHistoryRepository->clearSearchHistory($validatedData);
        $response   =   UtilityRepo::makeResponse(true,'Search History Cleared Successfully');
        return response()->json($response,200);
    }

    /* GET PRODUCTS OF ANY SPECIFIC DEPARTMENT OR ACCORDING TO SEARCH KEYWORD*/
    public function doProducts(Request $request){

        $param_dept     =   [];
        $validatedData  =   $this->validate($request,[
            'search'    =>  ['required'],
            'dept_id'   =>  ['nullable',new OnlyPostiveNumber,
                                function ($attribute, $value, $fail) use(&$param_dept){
                                    $param_dept   =   $this->utilityRepo->checkDepartmentExists($value);
                                    if(empty($param_dept)){
                                        $fail('The given :attribute is invalid.');
                                        return false;
                                    }
                                    $this->dept_level = $param_dept['level'];
                                }
                            ],
            'limit'     =>  ['filled',new OnlyPostiveNumber],
            'brands'    =>  ['filled','array'],
            'brands.*'  =>  ['required',new OnlyPostiveNumber],
            'promo'     =>  [Rule::in(0,1)],
        ]);

        $response   =   UtilityRepo::makeResponse(true,'Matched Products');
        $products   =   [];
        if(empty($validatedData['dept_id']) || is_null($validatedData['dept_id'])){
            $products   =   $this->repository->getProducts($request);
        }else{
            
            $dept_id    =   $validatedData['dept_id'];
            $request->request->add(['level' => $this->dept_level]);

            if($this->dept_level == 1){
                $sub_depts =   $this->departmentRepository->getSubDepartments($dept_id);
                $department_ids =   array_column($sub_depts,'id');
                $products   =   $this->repository->getProducts($request,$department_ids);
            }else
                $products   =   $this->repository->getProducts($request,$dept_id);
        }

        $totalResults = $products['total'];
        $products = $products['items'];

        $departments    =   [];
        $brands         =   [];
        $price_ranges   =   [];    
        $hierarchy      =   []; 
        if(!empty($products)){
            $dept_ids   =   array_unique(array_column($products,'dept_id'/*'dept_parent_id'*/));
            //$hierarchy  =   $this->departmentRepository->getMultiDepartmentsHierarchy($dept_ids);
            $hierarchy  =   $this->repository->getMultiDepartmentsHierarchyByKeyword($request);

            $dept_ids = array_filter($dept_ids);
            $departments = Department::find($dept_ids)->map(function($asdf) { return $asdf->toArray(); })->toArray();
            //$departments    =   $this->repository->getDepartmentsByKeyword($request);
            $departments    =   UtilityRepo::reArrangeRelationalArrays($departments,'name');
            $brands         =   $this->repository->getBrandsByKeyword($request);
            $brands         =   UtilityRepo::reArrangeRelationalArrays($brands,'brand');
            $price_ranges   =   $this->repository->getPriceRangesByKeyword($request);
            if ( $_SERVER['REMOTE_ADDR'] == '68.61.217.185' ) {
                //var_dump($price_ranges);
                //exit;
            }
            //$products['promo_price_filter'] =   ($this->repository->checkPromoProducts($request) > 0)? true : false;
            //$products['search']   =   $request->get('search');
        }
        /*
         * current_page: 1
         * data: []
         * first_page_url: "https://api.ezadtv.com/products?search=safety&page=1"
         * from: null
         * last_page: 1
         * last_page_url: "https://api.ezadtv.com/products?search=safety&page=1"
         * next_page_url: null
         * path: "https://api.ezadtv.com/products"
         * per_page: 48
         * prev_page_url: null
         * promo_price_filter: false
         * search: "safety"
         * to: null
         * total: 0
         */

        $curPage = $request->get('page');
        $lastPage = ceil($totalResults / 48);

        $products = [
            'current_page' => $request->get('page'),
            'data' => array_values($products),
            'first_page_url' => 'https://api.ezadtv.com/products?search=' . urlencode($request->get('search')) . '&page=1',
            'from' => $request->get('from'),
            'last_page' => $lastPage,
            'last_page_url' => 'https://api.ezadtv.com/products?search=' . urlencode($request->get('search')) . '&page=' . $lastPage,
            'next_page_url' => null,
            'path' => 'https://api.ezadtv.com/products',
            'per_page' => 48,
            'prev_page_url' => null,
            'promo_price_filter' => $this->repository->checkPromoProducts($request) > 0,
            'search' => $request->get('search'),
            'to' => $request->get('from') + 48,
            'total' => $totalResults,
        ];

        foreach ( $products['data'] as &$product ) {
            $product = TaxRepo::modifyProduct($product);
        }

        $response['data']                   =   $products;//array_values($products); 
        $response['departments_hierarchy']  =   $hierarchy;
        $response['departments']            =   $departments;
        $response['brands']                 =   $brands;
        $response['price_ranges']           =   $price_ranges;
        return response()->json($response,200);
    }
    
    public function doProductDetails(Request $request)
    {
        $businessId = ApiRequestContext::$businessId;

        $validatedData  =   $this->validate($request,[
            'param' =>  ['required'],
        ]);

        $validatedData['business_id'] = $businessId;

        /* checks product exists in our system or not */
        if(!$this->repository->checkProductByUpcSku($validatedData)){
            return response()->json(
                UtilityRepo::makeResponse(false,'Invalid UPC or SKU'),
                404
            );
        }

        $makeResponse   =   UtilityRepo::makeResponse(true);
        $product        =   $this->repository->getBusinessProductByUpcSku($validatedData);
        $parent_details =   Arr::pull($product,'parent_department_details');

        $bread_crumbs   =   [];
        if(!is_null($parent_details)){
            $bread_crumbs[] =   ['name' => 'Home','id' => '#'];
            $bread_crumbs[] =   ['name' => 'Search Results','id'=>  $parent_details['id']];
            $bread_crumbs[] =   ['name' =>  $product['title'],'id'  =>  @$product['upc']];
        }

        /*$storeId = $request->header('Store-Id', ApiRequestContext::$businessId);
        $pos = $this->businessRepo->getStorePosSettings($storeId);
        // if the product is in stock, hide the number
        if ( !$pos['show_stock_level'] && $product['num_inventory'] > 0 ) {
            $product['num_inventory'] = -1;
        }
        $product['show_special'] = (bool)$pos['show_oos_special'];*/

        $product = TaxRepo::modifyProduct($product);
        $response['data']   =   $product;         
        $response['data']['breadcrumbs']=   $bread_crumbs;

        return response()->json($response,200);
    }
