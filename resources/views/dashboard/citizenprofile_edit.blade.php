@extends('layouts.citizen')

@section('title', 'Edit Profile')
@section('page-title', 'Edit Profile')

@section('content')
@php
    $currentUser = Auth::user();
@endphp
<div class="max-w-3xl mx-auto">
    <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Edit Profile</h3>
            <p class="text-sm text-gray-600">Update your personal information. Verification details (NID, status) are managed separately.</p>
        </div>

        <form method="POST" action="{{ route('citizen.profile.update') }}" class="px-6 py-6 space-y-6">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Display Name <span class="text-red-500">*</span></label>
                    <input type="text" id="name" name="name" value="{{ old('name', $currentUser->name) }}"
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 {{ $errors->has('name') ? 'border-red-500' : 'border-gray-300' }}"
                           required>
                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="full_name" class="block text-sm font-medium text-gray-700 mb-2">Full Name (as on NID)</label>
                    <input type="text" id="full_name" name="full_name" value="{{ old('full_name', $currentUser->full_name) }}"
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 {{ $errors->has('full_name') ? 'border-red-500' : 'border-gray-300' }}">
                    @error('full_name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-2">Email <span class="text-red-500">*</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email', $currentUser->email) }}"
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 {{ $errors->has('email') ? 'border-red-500' : 'border-gray-300' }}"
                           required>
                    @error('email')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                    <input type="text" id="phone_number" name="phone_number" maxlength="11"
                           value="{{ old('phone_number', $currentUser->phone_number) }}"
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 {{ $errors->has('phone_number') ? 'border-red-500' : 'border-gray-300' }}"
                           placeholder="11-digit phone">
                    @error('phone_number')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="permanent_address" class="block text-sm font-medium text-gray-700 mb-2">Permanent Address</label>
                    <textarea id="permanent_address" name="permanent_address" rows="2"
                              class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 {{ $errors->has('permanent_address') ? 'border-red-500' : 'border-gray-300' }}">{{ old('permanent_address', $currentUser->permanent_address) }}</textarea>
                    @error('permanent_address')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="present_address" class="block text-sm font-medium text-gray-700 mb-2">Present Address</label>
                    <textarea id="present_address" name="present_address" rows="2"
                              class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 {{ $errors->has('present_address') ? 'border-red-500' : 'border-gray-300' }}">{{ old('present_address', $currentUser->present_address) }}</textarea>
                    @error('present_address')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="pt-4 border-t border-gray-200 flex justify-end space-x-3">
                <a href="{{ route('citizen.profile') }}"
                   class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </a>
                <button type="submit"
                        class="inline-flex items-center bg-green-600 py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
