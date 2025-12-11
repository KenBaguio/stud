<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ClerkController extends Controller
{
    // GET ALL CLERKS
    public function index(Request $request)
    {
        $search = $request->query('search');
        $limit = $request->query('limit', 5);
        $limit = min((int) $limit, 100);

        $query = User::where('role', 'clerk')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy('id', 'desc');

        $clerks = $query->paginate($limit);

        return response()->json($clerks);
    }

    // CREATE CLERK
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'first_name' => 'required|string|max:50',
                'last_name'  => 'required|string|max:50',
                'email'      => 'required|email|unique:users,email',
                'phone'      => 'nullable|digits:11|unique:users,phone',
                'password'   => 'required|confirmed|min:6',
            ], [
                'first_name.required' => 'First name is required.',
                'last_name.required'  => 'Last name is required.',
                'email.required'      => 'Email address is required.',
                'email.email'         => 'Please provide a valid email address.',
                'email.unique'        => 'This email is already registered.',
                'phone.unique'        => 'This phone number is already registered.',
                'password.required'   => 'Password is required.',
                'password.confirmed'  => 'Passwords do not match.',
                'password.min'        => 'Password must be at least 6 characters.',
            ]);

            $clerk = User::create([
                'first_name'      => $data['first_name'],
                'last_name'       => $data['last_name'],
                'email'           => $data['email'],
                'phone'           => $data['phone'] ?? null,
                'password'        => Hash::make($data['password']),
                'is_organization' => false,
                'role'            => 'clerk',
            ]);

            return response()->json([
                'message' => 'Clerk account created successfully',
                'clerk'   => $clerk,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }
    }

    // UPDATE CLERK
    public function update(Request $request, $id)
    {
        $clerk = User::where('role', 'clerk')->findOrFail($id);

        try {
            $data = $request->validate([
                'first_name' => 'sometimes|string|max:50',
                'last_name'  => 'sometimes|string|max:50',
                'email'      => 'sometimes|email|unique:users,email,' . $clerk->id,
                'phone'      => 'nullable|digits:11|unique:users,phone,' . $clerk->id,
                'password'   => 'nullable|confirmed|min:6',
            ]);

            $clerk->update([
                'first_name' => $data['first_name'] ?? $clerk->first_name,
                'last_name'  => $data['last_name'] ?? $clerk->last_name,
                'email'      => $data['email'] ?? $clerk->email,
                'phone'      => $data['phone'] ?? $clerk->phone,
                'password'   => isset($data['password']) ? Hash::make($data['password']) : $clerk->password,
            ]);

            return response()->json([
                'message' => 'Clerk account updated successfully',
                'clerk'   => $clerk,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);
        }
    }

    // DELETE CLERK
    public function destroy($id)
    {
        $clerk = User::where('role', 'clerk')->findOrFail($id);
        $clerk->delete();

        return response()->json([
            'message' => 'Clerk account deleted successfully',
        ]);
    }
}
