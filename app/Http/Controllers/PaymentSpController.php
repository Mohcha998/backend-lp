<?php

namespace App\Http\Controllers;

use App\Models\Payment_Sps;
use App\Models\ProspectParent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Xendit\Configuration;
use Xendit\Invoice\CreateInvoiceRequest;
use Xendit\Invoice\InvoiceApi;

class PaymentSpController extends Controller
{
    public function __construct()
    {
        Configuration::setXenditKey(env('XENDIT_SECRET_KEY'));
    }

    public function createInvoice(Request $request)
    {
        try {
            $validated = $request->validate([
                'id_parent' => 'required|exists:prospect_parents,id',
                'total' => 'required|integer|min:1',
                'payer_email' => 'required|email',
                'description' => 'required|string|max:255',
            ]);

            $lastPayment = Payment_Sps::latest('id')->first();
            $lastId = $lastPayment ? $lastPayment->id : 0;

            $nextId = $lastId + 1;

            $order = new Payment_Sps();
            $order->id_parent = $validated['id_parent'];
            $order->no_invoice = 'MREVID-' . now()->format('Ymd') . str_pad($nextId, 5, '0', STR_PAD_LEFT);
            $order->total = $validated['total'];
            $order->payer_email = $validated['payer_email'];
            $order->description = $validated['description'];

            $createInvoice = new CreateInvoiceRequest([
                'external_id' => $order->no_invoice,
                'amount' => $order->total,
                'payer_email' => $order->payer_email,
                'description' => $order->description,
                'invoice_duration' => 86400,
            ]);

            $apiInstance = new InvoiceApi();
            $generateInvoice = $apiInstance->createInvoice($createInvoice);

            $order->link_pembayaran = $generateInvoice['invoice_url'];
            $order->status_pembayaran = 0;
            $order->save();

            return response()->json([
                'message' => 'Invoice created successfully',
                'checkout_link' => $order->link_pembayaran,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => 'An error occurred',
                'message' => $th->getMessage(),
            ], 500);
        }
    }

    public function handleXenditCallback(Request $request)
    {
        try {
            $payload = $request->all();

            if (!isset($payload['id']) || !isset($payload['status'])) {
                return response()->json(['error' => 'Invalid payload'], 400);
            }

            $payment = Payment_Sps::where('no_invoice', $payload['id'])->first();

            if (!$payment) {
                return response()->json(['error' => 'Payment not found'], 404);
            }

            $biayaAdmin = isset($payload['amount']) ? ($payload['amount'] * 0.02) : 0;

            $payment->update([
                'status_pembayaran' => $this->mapXenditStatus($payload['status']),
                'payment_method' => $payload['payment_method'] ?? 'Unknown',
                'biaya_admin' => $biayaAdmin,
                'date_paid' => now()
            ]);

            return response()->json(['message' => 'Callback processed successfully']);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error processing callback',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    private function mapXenditStatus($xenditStatus)
    {
        switch ($xenditStatus) {
            case 'PAID':
                return 1;
            case 'PENDING':
                return 0;
            case 'EXPIRED':
                return 2;
            default:
                return 0;
        }
    }


    public function index()
    {
        return response()->json(Payment_Sps::all());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_parent' => 'required|exists:prospect_parents,id',
            'link_pembayaran' => 'nullable|string|max:255',
            'no_invoice' => 'required|string|max:255',
            'no_pemesanan' => 'nullable|string|max:255',
            'date_paid' => 'required|date',
            'status_pembayaran' => 'required|integer',
            'biaya_admin' => 'nullable|numeric|min:0',
            'total' => 'required|integer',
        ]);

        $paymentSp = Payment_Sps::create($validated);

        return response()->json($paymentSp, 201);
    }

    public function show(Payment_Sps $paymentSp)
    {
        return response()->json($paymentSp);
    }

    public function update(Request $request, Payment_Sps $paymentSp)
    {
        $validated = $request->validate([
            'id_parent' => 'required|exists:prospect_parents,id',
            'link_pembayaran' => 'nullable|string|max:255',
            'no_invoice' => 'required|string|max:255',
            'no_pemesanan' => 'nullable|string|max:255',
            'date_paid' => 'required|date',
            'status_pembayaran' => 'required|integer',
            'biaya_admin' => 'nullable|numeric|min:0',
            'total' => 'required|integer',
        ]);

        $paymentSp->update($validated);

        return response()->json($paymentSp);
    }

    public function destroy(Payment_Sps $paymentSp)
    {
        $paymentSp->delete();

        return response()->json(['message' => 'PaymentSp deleted successfully'], 200);
    }
}
