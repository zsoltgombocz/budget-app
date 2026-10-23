@extends('errors::minimal')

@section('title', __('Server Error'))
@section('code', '500')
@section('message', __('Server Error'))
@section('detail', __('Something broke on our side. Try again in a few minutes.'))
