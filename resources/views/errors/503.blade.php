@extends('errors::minimal')

@section('title', __('Service Unavailable'))
@section('code', '503')
@section('message', __('Service Unavailable'))
@section('detail', __('We are updating. Back in a few minutes.'))
